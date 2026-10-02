# Como a sincronização de contratações funciona

Guia de consulta dos três comandos que espelham contratações e documentos do PNCP no Postgres local. Escrito para quem construiu o fluxo e precisa relembrar a regra, o motivo e o que contar para outra pessoa.

O mapa completo da integração (órgãos, PCA, breaker, workers) continua em [`integracao-pncp.md`](integracao-pncp.md). Este arquivo cobre só contratações.

O PNCP é lento, cai por horas e o WAF dele dispara 429 quando várias chamadas saem juntas. A API da aplicação nunca fala com o PNCP: lê o Postgres. Quem fala com o PNCP são jobs em background, no ritmo que a API deles aguenta.

---

## Agenda

Tudo isso está em `contratacoes/routes/console.php`.

| Comando | Quando | O que faz |
|---|---|---|
| `pncp:sync-contratacoes` | Todo dia às **00:45** | Pede ao PNCP o que mudou ontem e hoje, uma modalidade por vez (1 a 10), e grava as contratações. No fim de cada modalidade, dispara a busca otimista de documentos. |
| `pncp:sync-documentos-pendentes` | A cada **20 minutos** | Caminho principal dos documentos. Pega até 200 contratações por modalidade que ainda estão com `documentos_sincronizados_em` nulo. |
| `pncp:sync-contratacoes-faltantes` | **Sábado e domingo às 02:00** | Relê `ciclos_contratacao` e refaz só os intervalos em que o PNCP anunciou mais registros do que o ciclo conseguiu gravar. |

00:45 e não 03:00: às 3h o pool de conexões do PNCP satura, porque muita gente agenda no mesmo horário, e as respostas voltam 500 por cerca de 25 minutos.

---

## Regras que valem para os três

### Um pedido a cada 2 segundos, no mundo inteiro

`PncpClient` usa um limitador Redis (`pncp:global`): **1 requisição a cada 2 segundos**, somando todos os workers e os dois endpoints (listagem e `/arquivos`). Não é "N por segundo". O WAF do PNCP já devolveu 429 com poucas chamadas concentradas (8 em 14 segundos, em 05 e 06/09). Quem espera vaga por mais de 30 segundos é tratado como indisponibilidade e volta para a fila.

### Dois circuit breakers, um para cada endpoint

Escopos separados: `contratacoes` e `documentos`. Queda do `/arquivos` não pausa a listagem do dia, e o contrário também vale.

- Um 500 isolado não pausa nada. A API falha por requisição.
- 3 falhas 5xx/timeout seguidas abrem pausa: 1, 3, 10 e depois 20 minutos.
- Um 429 abre pausa na hora: 2, 5, 10 e depois 20 minutos.
- Se a sondagem no nível máximo falha 3 vezes seguidas, a pausa vira 60 minutos e o log sobe para `critical`. Foi o que prendeu o breaker por ~6 horas em 05/09, quando cada sonda falha renovava a pausa de 20 minutos.

Enquanto o breaker está aberto, o job faz `release()` e volta depois da pausa mais um jitter (5–45 s). Só um job sonda quando a pausa vence; os outros esperam 30–90 s. O contador do ciclo **não avança** nesse `release`.

`retryUntil` existe porque cada `release()` conta como tentativa no Laravel. Sem limite por relógio, um job que só espera o breaker morreria de `MaxAttemptsExceeded` sem ter falhado de verdade.

| Escopo | `retryUntil` | Por quê |
|---|---|---|
| Contratações | **12 horas** | A listagem é barata (~244 páginas/dia, ~8 minutos de limitador) e insubstituível. Página que morre não entra no banco, e nenhum ciclo futuro olha para ela. 12 h absorvem 3 ou 4 outages de 1 h e ainda morrem antes do cron seguinte (00:45 + 12 h = 12:45). Em 15/09, com teto de 6 h, as páginas das modalidades 4, 6, 8 e 9 morreram no meio de pausas de 1 h e a fase de documentos dessas modalidades nunca disparou. |
| Documentos | **2 horas** | Cada lote é pequeno e recuperável. O registro continua com `documentos_sincronizados_em` nulo e a janela seguinte (≤ 20 min) o pega de novo. Insistir 6 h contra o PNCP fora do ar só ocupa worker e queima vaga do limitador. |

### Timeout do job e timeout do HTTP são coisas diferentes

O `$timeout` do job é o tempo que o worker pode ficar com ele antes de matá-lo. O timeout HTTP é o tempo que uma chamada ao PNCP pode demorar.

| Peça | Timeout do job | Timeout HTTP |
|---|---|---|
| `SyncContratacoesJob` (mestre da listagem) | 5 min | 40 s, 2 tentativas, 3 s entre elas |
| `ProcessContratacaoPageJob` | 2 min | os mesmos 40 s (1 chamada por página) |
| `SyncDocumentosJob` e `SyncDocumentosPendentesJob` | 5 min | nenhum: só leem o banco e enfileiram |
| `ProcessDocumentoPageJob` | **15 min** | 30 s por arquivo, 2 tentativas |

O 40 s da listagem e os 30 s dos documentos foram medidos em produção. Respostas boas já levaram 5 s, 27 s e 29 s. O erro de pool do PNCP chega em ~49 s, então é cortado como timeout: o que importa é "falhou". Os 10 s antigos do documento cortavam justo a faixa de 10–30 s, que é onde o PNCP responde sob carga. Em 15/09, ~58% das vagas do limitador viraram `cURL 28 after 10002ms`.

Connect timeout é 5 s nos dois.

### A coluna que decide se o documento já foi buscado

`contratacoes.documentos_sincronizados_em`.

- `NULL`: ainda não fomos ao `/arquivos`. Entra na Fase 2 e na drenagem.
- Preenchido: já fomos. Vale para lista de arquivos e também para **404** (compra sem anexo). O 404 grava `documentos = []` e preenche a coluna, para não ficar rebuscando para sempre.

A listagem, no insert, grava `documentos = []` e deixa a coluna nula. No update de uma contratação que já existe, **não mexe** em `documentos` nem em `documentos_sincronizados_em`. Reprocessar a listagem não apaga anexo já baixado.

---

## 1. `pncp:sync-contratacoes`

Arquivo: `app/Console/Commands/SyncContratacoesCommand.php`.

### O que o comando faz

Não fala com o PNCP. Monta um `ListContratacoesDTO` e enfileira um `SyncContratacoesJob` por modalidade.

Padrão do cron (`--days=1`):

- `dataInicial` = ontem (`Ymd`)
- `dataFinal` = hoje (`Ymd`)

É uma janela de dois dias de calendário, não "só ontem". Às 00:45, "hoje" ainda tem 45 minutos. O dia de calendário cheio é capturado na madrugada seguinte, quando ele vira `dataInicial`. O overlap existe para a fronteira da meia-noite e para um único cron perdido: a execução do dia seguinte ainda pede o dia anterior. Dois crons seguidos mortos **antes** de abrir ciclo deixam o dia mais antigo de fora, e o reprocessador de fim de semana não vê isso, porque não existe linha em `ciclos_contratacao`.

Flags manuais:

- `--days=30` para carga relativa.
- `--data-inicial=Ymd --data-final=Ymd` para um intervalo absoluto. As duas juntas; se presentes, `--days` é ignorado. É o jeito de reprocessar um gap depois da meia-noite.
- `--modalidades=4,6,8,9` restringe. Vazio = 1 a 10. ID fora dessa faixa é descartado.

`uf` e `codigoMunicipioIbge` saem sempre nulos nesse fluxo. O `limit` do DTO é 50, o máximo que o PNCP aceita.

### Por que o endpoint é o de atualização

```
GET https://pncp.gov.br/api/consulta/v1/contratacoes/atualizacao
```

`PncpClient::getContratacoes()` usa esse URL por padrão (`$porAtualizacao = true`). Existe `.../publicacao`, e ninguém chama. Atualização traz o que foi publicado **ou alterado** no intervalo, inclusive edital antigo retificado ontem. Publicação perderia a retificação.

Parâmetros: `dataInicial`, `dataFinal`, `codigoModalidadeContratacao`, `pagina`, `tamanhoPagina=50`. Nulos somem pelo `array_filter`.

### Modalidades

Um job por código, porque o PNCP exige o filtro e o volume é muito desigual. As que realmente enchem a fila são a 6 (pregão eletrônico), a 8 (dispensa), a 9 (inexigibilidade) e a 4 (concorrência eletrônica). A 6 sozinha fica na casa de ~5.300 registros por dia. As outras muitas vezes voltam vazias.

| Código | Nome no PNCP |
|---|---|
| 1 | Leilão eletrônico |
| 2 | Diálogo competitivo |
| 3 | Concurso |
| 4 | Concorrência eletrônica |
| 5 | Concorrência presencial |
| 6 | Pregão eletrônico |
| 7 | Pregão presencial |
| 8 | Dispensa de licitação |
| 9 | Inexigibilidade |
| 10 | Manifestação de interesse |

### O job mestre

`SyncContratacoesJob`. Único por `modalidade:dataInicial:dataFinal` durante 12 h. Dez modalidades no mesmo comando não colapsam num job só, e um redespacho igual (o de fim de semana, ou um clique duplicado) é descartado enquanto o lock existir.

Ele faz **uma** chamada, a página 1, só para ler `totalPaginas` e `totalRegistros`.

- Resposta vazia ou 204: grava um ciclo `empty` (anunciado 0) e para. Não é gap. Não dispara documentos.
- Com dados: gera um `sync_id`, abre o ciclo no Redis (`PncpSyncCycleTracker`, TTL 24 h) e uma linha em `ciclos_contratacao` com status `started`, `total_anunciado` e `total_paginas`.

Depois enfileira um `ProcessContratacaoPageJob` **por página, da 1 até N**, inclusive a página 1 de novo. A sondagem do mestre não grava contratação. Quem grava é o job da página. O stagger entre páginas (`PNCP_PAGE_STAGGER_SECONDS`) fica em 0: com poucos workers a fila já serializa, e o limitador global é o teto real.

Se o mestre esgota as 12 h depois de ter gravado o START, `failed()` marca o ciclo `abandoned`.

### O job da página

`ProcessContratacaoPageJob` faz uma chamada daquela página e entrega o array para `SyncContratacaoAction`.

Não é "uma requisição por registro". São `totalPaginas` requisições, cada uma com até 50 contratações, e **um upsert em massa por página** (uma query, chave `numero_controle_pncp`).

O que entra na linha:

- Chave natural `numero_controle_pncp`. Sem ela o item é descartado.
- Colunas de consulta: órgão, ano, sequencial, UF, município, modalidade, situação, valor em **centavos**, datas, SRP, objeto.
- `payload_completo`: o JSON inteiro do PNCP.
- `sequencial_compra` resolvido em três lugares, porque o PNCP não manda sempre na raiz: campo da raiz, primeiro item de `documentos[]`, ou o pedaço do `numeroControlePNCP` no formato `{cnpj}-1-{sequencial}/{ano}`. Sem esse inteiro a fase de documentos ignora a linha (`whereNotNull('sequencial_compra')`).

Cada página concluída incrementa o contador Redis. `release` por breaker não incrementa. Quando `páginas feitas == total de páginas`, a última página:

1. Grava em `ciclos_contratacao` o status `finished`, `itens_processados` (soma dos itens que as páginas devolveram) e `paginas_completas`.
2. Loga `PNCP_CONTRATACOES_FINISHED`.
3. Enfileira `SyncDocumentosJob` com atraso.

`itens_processados` conta **itens recebidos na resposta**, não inserts. Upsert atualiza linha que já existia. Por isso a conta do gap não é "total anunciado menos linhas novas".

Página que esgota as 12 h conta como 0 itens. Se as outras páginas fecharem o ciclo, ele fica `finished` abaixo do anunciado (gap). Se não fecharem, o ciclo vai para `abandoned`.

### Por que a busca de documentos espera a modalidade terminar

A listagem e o `/arquivos` têm custo e falha diferentes. Listagem são centenas de páginas. Documentos são **uma chamada por contratação** (~12.200 por dia). Juntar os dois faria a fase cara segurar a fase barata, que é a que não pode se perder.

O atraso entre modalidades é de 8 minutos vezes a posição do dia (`PncpDocumentsDispatchSequencer`). A primeira modalidade que termina espera 0; a segunda, 8; a terceira, 16. O contador é diário e o TTL de 24 h é fixado pela primeira, sem renovar. Sem isso, as modalidades terminam juntas (foram disparadas juntas às 00:45) e bombardeiam `/arquivos` ao mesmo tempo. Foi esse pico que, em 05/09, segurou o breaker no máximo por ~6 horas.

---

## 2. Documentos logo depois da listagem (Fase 2)

`SyncDocumentosJob`. Não tem cron. Quem dispara é a última página da modalidade.

Ele **não** varre toda contratação com documento nulo. O recorte é:

- `documentos_sincronizados_em` nulo
- mesma modalidade
- `updated_at >=` início deste ciclo (o upsert da listagem toca `updated_at`)
- `sequencial_compra` preenchido
- mais recentes por `data_publicacao_pncp` primeiro
- **teto de 200** (`pending_documents_max_per_run`)

O recorte por `updated_at`, e não por data de publicação, é de propósito. O endpoint de atualização traz licitação publicada meses atrás e alterada ontem. Filtrar por publicação deixaria essa fora da Fase 2 para sempre. O teto de 200 existe porque a modalidade 6, sem teto, despachava ~5.300 registros (~2,9 h de limitador), estourava o `retryUntil` de 2 h e ainda segurava a trava da modalidade, bloqueando a drenagem exatamente onde o volume é maior. O que passar de 200 continua nulo e entra na janela de 20 minutos.

Antes de enfileirar, pede uma trava Redis por modalidade (`tryStartModalidade`, `Cache::add`, TTL 3 h). Se a drenagem já estiver com aquela modalidade, o job desiste e loga `PNCP_DOCUMENTOS_SKIPPED_COLLISION`. Os registros continuam nulos.

O que passar é fatiado em lotes de **10** (`documents_lote_size`) e cada lote vira um `ProcessDocumentoPageJob`.

Único por `docs:{modalidade}:{dataInicial}:{dataFinal}` por 2 h, para dois ciclos do mesmo dia não duplicarem a Fase 2.

### O lote de 10

`ProcessDocumentoPageJob`, para cada item ainda pendente:

```
GET https://pncp.gov.br/api/pncp/v1/orgaos/{cnpj}/compras/{ano}/{sequencial}/arquivos
```

- 200: grava o JSON e preenche `documentos_sincronizados_em`.
- 404: compra sem anexo. Grava `[]` e preenche a coluna. É sucesso.
- 5xx, timeout ou falta de vaga no limitador: não grava `[]`. Propaga. Senão um apagão viraria "não tem anexo".

Se a falha acontece no meio do lote, o que já voltou é gravado **antes** do `release`. Na próxima tentativa, `itemsAindaPendentes()` tira do lote quem já tem a coluna preenchida. Com lote de 50 e ~30% de falha por item, a chance de o lote fechar inteiro era ~0,00002%. Foi a origem dos `attempt: 31..51` em 15/09. Dez itens cabem nos 15 minutos do job: pior caso é 10 × (2 × 30 s + 3 s) = 630 s, com folga para o limitador e o banco. Lote de 50 a 30 s por item passaria de 15 minutos e o worker mataria o job no meio.

A gravação é um `UPDATE` por registro, só nas duas colunas de documento, dentro de uma transação. Não mexe no restante da contratação.

Quando o último lote do `sync_id` termina, solta a trava da modalidade (só se o `sync_id` ainda for o dono, para um lote atrasado não apagar a trava de um ciclo mais novo). Lote que esgota as 2 h também conta no ciclo (com 0 itens) e solta a trava. Sem isso a modalidade fica presa até o TTL e a drenagem só loga colisão, que foi o caso da modalidade 9 em 12/09.

---

## 3. `pncp:sync-documentos-pendentes`

Arquivo: `app/Console/Commands/SyncDocumentosPendentesCommand.php`. Cron `*/20 * * * *`.

É o caminho principal dos documentos, não um reforço ocasional. A Fase 2 é o caminho otimista: pega no máximo 200 do ciclo que acabou de rodar. Todo o resto, e tudo que o `/arquivos` recusou, fica para esta drenagem.

### Por que a cada 20 minutos, e não duas vezes por dia

As rajadas das 09h e das 21h (teto de 2.000 por modalidade, janela de 6 h) não convergiam:

- A modalidade 6 publica ~5.300 por dia. 2 × 2.000 = 4.000. O passivo crescia ~1.300 por dia mesmo com 100% de sucesso.
- Concentrar o volume em duas janelas fazia documentos e listagem brigarem pelo mesmo limitador, e as duas caíam.
- Um lote que pegava pausa de 1 h morria de `retryUntil` e só tinha outra chance 12 h depois.

A cada 20 minutos a janela é pequena, um lote abandonado volta em no máximo 20 minutos, e a carga fica espalhada.

### O que cada janela faz

O comando enfileira um `SyncDocumentosPendentesJob` por modalidade, com **1 minuto** de atraso entre elas. O stagger de 8 minutos da Fase 2 não serve aqui: 10 × 8 = 72 minutos, e as últimas modalidades nasceriam depois da janela seguinte.

O job mestre:

- Único por modalidade por **15 minutos** (`uniqueFor = 900`). Tem que ser menor que 20, senão a própria trava engole a janela seguinte. Com trava de 6 h e id por dia, de 18 disparos em 6 h, 17 eram descartados em silêncio.
- `retryUntil` de 20 minutos. Ele só lê o banco e despacha. Se não conseguir nesta janela, a próxima já vê o que ainda está nulo. Insistir despacharia um recorte velho.
- Consulta sem filtro de data: `documentos_sincronizados_em` nulo, mesma modalidade, `sequencial_compra` preenchido, publicação mais recente primeiro, **teto de 200 por modalidade**.
- Mesma trava da Fase 2. Se a Fase 2 ainda está na modalidade, desiste. Foi a falta dessa trava que fez a varredura das 09h duplicar a Fase 2 em 11/09.
- Fatia em lotes de 10 e usa o mesmo `ProcessDocumentoPageJob`.

### A conta do teto de 200

Limitador: 1 req / 2 s = 1.800 req/h = **600 requisições por janela de 20 min**.

Modalidades com volume real são ~4. 200 × 4 = 800, um pouco acima do teto limpo. O limitador serializa o excesso e a janela estica. Isso é aceitável enquanto houver passivo (backfill ou outage). Se lotes começarem a morrer de `retryUntil` com o PNCP saudável, baixe o teto, não o intervalo.

Média histórica: ~12.200 documentos/dia ÷ 72 janelas ≈ 170 por janela. 200 dá folga. No teto, 72 × 200 = 14.400 por modalidade por dia, acima do fluxo da modalidade 6.

Os três números andam juntos: `routes/console.php` (o cron), `pending_documents_interval_minutes` e `pending_documents_max_per_run` em `config/pncp.php`.

---

## 4. `pncp:sync-contratacoes-faltantes`

Arquivo: `app/Console/Commands/SyncContratacoesFaltantesCommand.php`. Sábado e domingo às 02:00, horário de tráfego baixo, duas chances na semana.

Não varre a tabela `contratacoes`. Varre `ciclos_contratacao`, que é a auditoria durável. O Redis só conta páginas em voo para saber quando disparar a Fase 2; o log roda e some. O gap tem que sobreviver a isso.

### O que é um ciclo em gap

`CicloContratacao::scopeEmGap()`, e ainda `total_anunciado - itens_processados > 0`:

| Status | Entra no reprocessamento quando |
|---|---|
| `abandoned` | O mestre ou uma página esgotou as 12 h e o ciclo não fechou, e ainda falta item em relação ao anunciado. |
| `finished` | Fechou, mas `itens_processados < total_anunciado`. Página que voltou vazia, ou que morreu e foi contada como 0. |
| `started` | Aberto há mais de 12 h. Job morreu sem `failed()` limpo. |
| `empty` | Nunca. Anunciado 0. |

Um job por combinação distinta de modalidade + `data_inicial` + `data_final`. O comando redespacha o **mesmo** `SyncContratacoesJob` da fase 1, com as datas gravadas no ciclo (não "ontem"). O `uniqueId` junta duplicatas do mesmo par. O upsert por `numero_controle_pncp` torna a releitura idempotente: preenche o que faltou e atualiza o que já estava, sem duplicar linha e sem apagar documento.

O ciclo reprocessado nasce com **outro** `sync_id`. A linha antiga em gap não é reescrita. No sábado seguinte ela continua em gap, então o intervalo é pedido de novo. É seguro por causa do upsert, e é o preço de o mecanismo ser autoalimentado: não há "já resolvi, pode esquecer" automático. Se o novo ciclo também falhar, ele vira mais uma linha em gap e entra na próxima madrugada de fim de semana.

### O que esse reprocessamento enxerga

- Página perdida no meio de um outage.
- Ciclo morto no meio (`abandoned` ou `started` velho).
- Intervalo que abriu ciclo e não entregou o anunciado.

### O que ele não enxerga

- Mestre que morreu **antes** da primeira resposta boa. Não há linha. O overlap de um dia do cron diário cobre um único cron perdido; dois seguidos, não.
- Anexo novo numa contratação que já tem `documentos_sincronizados_em` preenchido. A listagem atualiza o payload e não zera essa coluna. A drenagem só olha nulo.
- Registro que o PNCP apagou ou mudou de modalidade. O banco local só faz upsert.
- Conteúdo que mudou depois da página já ter sido gravada com sucesso naquele ciclo. A alteração volta se cair de novo na janela diária de atualização.
- `totalRegistros` mentindo entre a sondagem e as páginas. Vira gap falso. O reprocessamento é idempotente, então o prejuízo é releitura, não dado errado.

---

## Como contar em uma frase cada fase

1. **Meia-noite e quarenta e cinco.** Para cada modalidade, perguntamos ao PNCP o que mudou ontem e hoje, de 50 em 50. Gravamos a contratação sem ir buscar o edital. Quando a modalidade fecha, comparamos o que o PNCP anunciou com o que as páginas devolveram.
2. **Na sequência, com folga de 8 minutos entre modalidades.** Buscamos o edital das contratações que esse ciclo acabou de tocar, no máximo 200, em lotes de 10.
3. **A cada 20 minutos, o dia inteiro.** O que ficou sem edital — por teto, por queda do PNCP, por ciclo que nem chegou na fase de documentos — entra de novo, 200 por modalidade, mais recentes primeiro.
4. **Sábado e domingo de madrugada.** Se algum ciclo anunciou mais do que entregou, pedimos aquele intervalo de novo. O PNCP perde página; o upsert não duplica.

---

## Onde está no código

| Peça | Arquivo |
|---|---|
| Agenda | `routes/console.php` |
| Números (teto, stagger, timeout, breaker) | `config/pncp.php` |
| Comandos | `app/Console/Commands/SyncContratacoesCommand.php`, `SyncDocumentosPendentesCommand.php`, `SyncContratacoesFaltantesCommand.php` |
| Jobs | `app/Jobs/Contratacoes/SyncContratacoesJob.php`, `ProcessContratacaoPageJob.php`, `SyncDocumentosJob.php`, `SyncDocumentosPendentesJob.php`, `ProcessDocumentoPageJob.php` |
| HTTP | `app/Integrations/PNCP/PncpClient.php` |
| Upsert da contratação | `app/Actions/Contratacoes/SyncContratacaoAction.php` |
| Update do documento | `app/Actions/Contratacoes/SyncContratacaoDocumentosAction.php` |
| Contador em voo e trava de modalidade | `app/Integrations/PNCP/PncpSyncCycleTracker.php` |
| Linha durável do ciclo | `app/Integrations/PNCP/CicloContratacaoRecorder.php`, `app/Models/Contratacoes/CicloContratacao.php` |
| Atraso da Fase 2 | `app/Integrations/PNCP/PncpDocumentsDispatchSequencer.php` |
| Pausa e `release` | `app/Support/ErrorHandling/PncpFailureHandler.php` |
