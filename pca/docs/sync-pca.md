# Como a sincronização de PCA funciona

Guia de consulta do Plano de Contratações Anual: de onde saem os CNPJs, como o plano e os itens entram no Postgres, e o que o cron realmente dispara. Escrito para quem construiu o fluxo e precisa relembrar a regra, o motivo e o que contar para outra pessoa.

O mapa completo da integração continua em [`contratacoes/docs/integracao-pncp.md`](../../contratacoes/docs/integracao-pncp.md). O espelho das contratações está em [`contratacoes/docs/sync-contratacoes.md`](../../contratacoes/docs/sync-contratacoes.md).

O serviço `pca/` tem fila, workers e Redis próprios. O Postgres é o mesmo banco `pncp` que o serviço de contratações escreve e que a API lê. O limitador `pncp:global` deste Redis **não** é o das contratações: cada serviço admite 1 requisição a cada 2 segundos. O WAF do PNCP vê a soma dos dois.

---

## Agenda

Fonte da verdade: `pca/routes/console.php`.

| Comando | Quando | O que faz |
|---|---|---|
| `pncp:import-orgaos` | Todo dia às **12:00** | Lê os CNPJs distintos de `contratacoes` e faz upsert na allowlist `orgaos`. Zero HTTP. |
| `pncp:discover-unidades` | Todo dia às **12:10** | Inventário lateral em `unidades_orgao`. O sync de PCA não espera esta tabela. |
| `pncp:sync-pca` | **Comentado.** A linha guardada é `dailyAt('00:00')`. | Um job por órgão ativo, no ano corrente, para gravar `planos_pca` e `itens_pca`. |

Hoje o PCA só anda se alguém rodar `php artisan pncp:sync-pca` (ou `--ano=` / `--cnpj=`). Os outros dois comandos sobem sozinhos.

00:00, se a linha for descomentada, cai em cima da janela das contratações (00:45) e da drenagem de documentos (a cada 20 minutos). Os Redis são separados, então cada um segue em 1 chamada a cada 2 segundos, e o PNCP recebe o dobro. A allowlist dessa madrugada seria a do meio-dia anterior: CNPJ que apareceu em contratação depois das 12:00 só entra em `orgaos` no import seguinte.

---

## De onde vem o universo

O PNCP não tem “PCAs alterados ontem”. O plano é consultado **por CNPJ e por ano**:

```
GET https://pncp.gov.br/api/pncp/v1/orgaos/{cnpj}/pca/{ano}/...
```

Sem uma lista de CNPJs não há o que pedir. Essa lista é `orgaos` com `ativo = true`.

### `pncp:import-orgaos`

`ImportPncpOrgaosCommand` → `OrgaoRepository::distinctOrgaosFromContratacoes()`.

Uma query, sem chamar o PNCP:

```sql
DISTINCT ON (orgao_cnpj)
  razao_social, poder, esfera
  a partir de payload_completo->orgaoEntidade
  da contratação mais recente (data_atualizacao_global DESC)
```

`poder` e `esfera` são uma letra (`E|L|J|M` e `F|E|M`), vindos de `poderId` e `esferaId` do payload. Upsert em lotes de 500, chave `cnpj`.

Regras que o import não viola:

- `importado_de = 'manual'` é pulado. Serve para cadastrar órgão que ainda não apareceu numa contratação.
- No update, as colunas mexidas são só `razao_social`, `poder`, `esfera` e `updated_at`. `ativo` e `importado_de` ficam como estão. Desligar um órgão é decisão humana; o import não reativa.
- CNPJ que sumiu das contratações **continua** em `orgaos`. Não há desativação automática.

`--dry-run` só conta.

### O que o sync de PCA consome

`OrgaoRepository::listActive()`. Órgão com `ativo = false` não gera job. `--cnpj=` restringe a um. Ano padrão: `now()->year`. Ano anterior só entra com `--ano=`.

---

## `pncp:sync-pca`

`SyncPcaCommand` não fala com o PNCP. Para cada órgão ativo monta um `PcaSyncParamsDTO(ano, cnpj)` e enfileira um `SyncPcaDataJob`.

Não há `--force`. Houve, e ninguém lia. A unicidade do job (`{ano}:{cnpj}` por 12 h) já impede dois mestres do mesmo par ao mesmo tempo.

### Três níveis

```
órgão ativo
  SyncPcaDataJob          descobre os planos (sequenciais) e grava planos_pca
    SyncPcaItemsJob       um por sequencial; pergunta quantos itens existem
      ProcessPcaItemsPageJob   uma página de 50; grava itens_pca
```

`ciclos_pca` guarda os dois primeiros níveis na mesma tabela. A linha do órgão tem `sequencial_pca` nulo. A linha do plano tem o sequencial preenchido. São ciclos diferentes, com significados diferentes. Ler `status = finished` sem olhar o sequencial mistura “já enfileirei os planos” com “já gravei os itens”.

---

## 1. O mestre do órgão — `SyncPcaDataJob`

Timeout do job: 5 minutos. Timeout HTTP: 40 s, 2 tentativas, 3 s entre elas. `retryUntil`: 12 h (`pncp.retry_until_hours.pca`). Único por `ano:cnpj` por 12 h. Breaker no escopo `pca`.

Ele chama `PncpClient::getPcaUnidades()`:

```
GET /api/pncp/v1/orgaos/{cnpj}/pca/{ano}/consolidado/unidades
    ?pagina=&tamanhoPagina=50
```

A paginação **não** vira job. O client percorre as páginas dentro desta execução, até uma página curta, uma página vazia, ou 404 (404 devolve o que já acumulou). Cada página passa pelo limitador de 1 a cada 2 segundos. Com o PNCP lento, várias páginas podem estourar os 5 minutos do job; o `release` recomeça o loop da página 1, sem checkpoint.

O comentário no job cita `/consolidado`. Quem é chamado é `/consolidado/unidades`. `getPcaConsolidado()` e `getPcaValorCategoria()` existem no client e não têm chamador.

Cada elemento com `sequencialPca > 0` vira um plano. `codigoUnidade` e `nomeUnidade` saem do próprio payload (raiz ou `unidadeOrgao`). Isso é o que `planos_pca` precisa. A tabela `unidades_orgao` não entra aqui.

| Resposta | O que fica em `ciclos_pca` |
|---|---|
| Lista vazia ou 404 | Nada. Órgão sem PCA no ano não deixa rastro. |
| Veio unidade, nenhuma com sequencial | Linha do órgão, status `empty`. |
| Um ou mais sequenciais | Upsert dos planos, um `SyncPcaItemsJob` por plano, e a linha do órgão vai para `finished` **na hora**. |

Esse `finished` do órgão significa: os sub-mestres foram enfileirados. Os itens ainda não foram buscados. `total_anunciado` dessa linha continua 0. A conta de itens mora no ciclo do plano.

Upsert do plano (`SyncPcaPlanAction`), chave `(cnpj_orgao, ano_pca, sequencial_pca)`. Atualiza unidade, situação, datas e `payload_completo`. Não apaga itens.

Se o mestre esgota as 12 h depois de ter aberto o ciclo, `failed()` marca `abandoned`. Se morre antes de receber unidades, não há linha.

---

## 2. O sub-mestre do plano — `SyncPcaItemsJob`

Também 5 minutos de job, 12 h de `retryUntil`, mesmo breaker `pca`. **Não** é `ShouldBeUnique`. Dois despachos do mesmo sequencial podem paginar em paralelo. O upsert dos itens torna isso seguro e gasta vaga do limitador em dobro.

O endpoint de itens devolve um array, sem `totalPaginas`. Por isso existe uma chamada só de contagem:

```
GET /api/pncp/v1/orgaos/{cnpj}/pca/{ano}/{sequencial}/itens/quantidade
```

- 404 ou quantidade ≤ 0: grava ciclo do plano com status `empty` e para.
- Senão: `totalPaginas = ceil(quantidade / 50)`, abre contador Redis (`PncpSyncCycleTracker`, TTL 24 h) e uma linha em `ciclos_pca` com `sequencial_pca`, `total_anunciado` e `total_paginas`.

Depois enfileira um `ProcessPcaItemsPageJob` por página, da 1 até N. Não há stagger (`page_stagger_seconds` está no config e este job não usa). A página 1 não é sondada antes: a quantidade já dimensionou o fan-out, e cada página é buscada uma vez.

Se este job esgota as 12 h **antes** de criar o ciclo (a quantidade nunca respondeu), `failed()` **cria** uma linha `abandoned` com o sequencial. O gap fica visível. Se o ciclo já existia e ainda está `started`, ele é que vira `abandoned`.

---

## 3. A página — `ProcessPcaItemsPageJob`

Timeout do job: 5 minutos. Uma chamada:

```
GET /api/pncp/v1/orgaos/{cnpj}/pca/{ano}/{sequencial}/itens
    ?pagina=&tamanhoPagina=50
```

404 vira lista vazia e a página conta como 0 itens. Não é “plano sem item” no sentido do ciclo `empty`: a quantidade já disse que havia itens; esta página só não devolveu. O ciclo do plano pode fechar `finished` com `itens_processados` abaixo do anunciado.

Antes de gravar, o job procura a linha em `planos_pca` pelo trio cnpj + ano + sequencial, porque `itens_pca.plano_pca_id` é a FK. Se o upsert do mestre ainda não commitou, faz `release(30)` e **não** avança o contador. Log: `PCA_PLAN_NOT_FOUND`.

`SyncPcaItemsAction` faz um upsert da página inteira, chave `(plano_pca_id, numero_item)`. Valores ficam em `decimal` (não em centavos, ao contrário de `valor_total_estimado` das contratações). O item **não** guarda `payload_completo` — só as colunas mapeadas. `pdmDescricao` da API vira `pdm_nome`. `categoriaItemPcaid` vira `categoria_item_pca`.

A última página (`páginas feitas == total` no Redis) grava no ciclo do plano: `finished`, `itens_processados`, `paginas_completas`. `release` por breaker não incrementa. Página que esgota as 12 h conta como 0; se as outras não fecharem o ciclo, a linha vai para `abandoned`.

`itens_pca` tem `search_vector` gerado (tsvector em português, índice GIN) sobre descrição, classificação superior, grupo, unidade requisitante e PDM. A API de leitura usa isso. O job não escreve a coluna.

---

## O que `ciclos_pca` serve, e o que não serve

Não existe `pncp:sync-pca-faltantes`. Nada relê gap no fim de semana. A cura desenhada é rodar `pncp:sync-pca` de novo: o upsert converge plano e item ao que o PNCP devolver naquela passagem. Com o cron comentado, essa segunda passagem não acontece sozinha.

| Linha | `finished` quer dizer | Gap que dá para ver na tabela |
|---|---|---|
| Órgão (`sequencial_pca` nulo) | Os jobs de plano foram enfileirados. | `abandoned` se o mestre morreu depois do START. Órgão sem PCA não aparece. |
| Plano (sequencial preenchido) | As páginas daquele sequencial fecharam o contador. | `finished` com `itens_processados < total_anunciado`, `abandoned`, ou `started` velho. `empty` é quantidade zero, não é falha. |

O que uma releitura manual recupera: página perdida, quantidade que nunca respondeu, órgão cujo mestre morreu no meio.

O que nenhuma releitura apaga:

- Item que o PNCP removeu do plano. O banco só faz upsert.
- Ano que ninguém passou em `--ano=`. O padrão é o ano corrente.
- Órgão desligado (`ativo = false`) ou que ainda não está na allowlist.

---

## `pncp:discover-unidades`

Roda às 12:10, dez minutos depois do import. Não é etapa do sync de PCA. `SyncPcaDataJob` já recebe código e nome de unidade no mesmo endpoint e grava em `planos_pca`.

O comando existe para ter um inventário próprio em `unidades_orgao`, com geografia que o endpoint de PCA não manda.

Para cada órgão ativo do ano (padrão: ano corrente):

- Se já existe qualquer unidade daquele CNPJ+ano, pula. `--force` ignora essa trava. `--cnpj=` restringe.
- Senão enfileira `DiscoverPncpUnidadesJob` (timeout 2 minutos, `retryUntil` 12 h, breaker `pca`).

O job chama o mesmo `getPcaUnidades()`. UF, IBGE e município vêm de `contratacoes.payload_completo->unidadeOrgao`, o registro mais recente daquele `codigoUnidade`, sem HTTP extra. Unidade que nunca apareceu numa contratação fica com geo nulo. Upsert em `unidades_orgao`, chave `(cnpj_orgao, ano_pca, codigo_unidade)`, FK para `orgaos.cnpj` com cascade. `ativo` entra `true` no insert e não é atualizado depois.

Como a trava é “já tem linha neste ano”, o cron diário depois da primeira descoberta vira no-op para aquele órgão, até `--force` ou um CNPJ novo importado ao meio-dia.

---

## Regras compartilhadas com as contratações

O `pca/config/pncp.php` foi copiado do serviço de contratações. Deste arquivo, o sync de PCA usa de fato:

| Regra | Valor | Efeito no PCA |
|---|---|---|
| HTTP | connect 5 s, timeout 40 s, 2 tentativas, 3 s de espera | Vale para unidades, quantidade e itens. |
| Limitador | 1 requisição a cada 2 s, espera máxima 30 s | Só os workers deste Redis. |
| Breaker `pca` | 3 falhas 5xx abrem pausa (1, 3, 10, 20 min); 429 abre na hora (2, 5, 10, 20 min); 3 sondagens falhas no topo viram pausa de 60 min | Um escopo só. Unidades, planos e itens pausam juntos. |
| `retryUntil` | 12 h | `release()` não mata o job por excesso de tentativas. |
| Jitter | 5–45 s ao voltar da pausa; 30–90 s quando outro job já pegou a sondagem | Páginas represadas não voltam no mesmo segundo. |

`pending_documents_*`, `documents_lote_size` e `documents_stagger_minutes` neste config não têm job de PCA que os leia.

Pausa e `release`: `PncpFailureHandler`, igual ao das contratações. Falha de API devolve o job para a fila e o contador de páginas não anda. Bug ou 4xx segue a `JobExceptionPolicy`.

---

## Como a API lê

Quem escreve é o serviço `pca/`. Quem lê é o serviço `contratacoes/`, no mesmo Postgres:

`QUERY|GET /api/v1/pca/items` — join `itens_pca` com `planos_pca`, filtro por ano, CNPJ e categoria, busca full-text em português quando vem `search`, paginação por cursor em `(data_publicacao_pncp DESC, id)`.

---

## Como contar em uma frase cada fase

1. **Meio-dia.** Os CNPJs que já apareceram numa contratação viram a lista de órgãos. Desligar alguém dessa lista é manual.
2. **Dez minutos depois.** Para órgão que ainda não tem inventário no ano, buscamos as unidades do PCA e completamos UF e município com o que as contratações já guardaram. O sync do plano não usa essa tabela.
3. **Quando o `pncp:sync-pca` roda** (hoje, só na mão). Para cada órgão ativo, no ano corrente, perguntamos quais planos existem, gravamos o plano, e para cada sequencial perguntamos quantos itens são. Cada página de 50 vira um upsert. O ciclo do órgão fecha ao enfileirar; o ciclo do plano fecha quando a última página volta.
4. **Não há varredura de fim de semana.** Plano ou item que o PNCP deixou cair fica faltando até o comando rodar de novo. Com a agenda comentada, isso não é diário.

---

## Onde está no código

| Peça | Arquivo |
|---|---|
| Agenda | `pca/routes/console.php` |
| Números (timeout, breaker, limitador) | `pca/config/pncp.php` |
| Allowlist | `app/Console/Commands/ImportPncpOrgaosCommand.php`, `app/Repositories/Orgaos/OrgaoRepository.php` |
| Sync | `app/Console/Commands/SyncPcaCommand.php` |
| Jobs | `app/Jobs/Pca/SyncPcaDataJob.php`, `SyncPcaItemsJob.php`, `ProcessPcaItemsPageJob.php` |
| Unidades | `app/Console/Commands/DiscoverPncpUnidadesCommand.php`, `app/Jobs/Pncp/DiscoverPncpUnidadesJob.php` |
| HTTP | `app/Integrations/PNCP/PncpClient.php` |
| Upserts | `app/Actions/Pca/SyncPcaPlanAction.php`, `SyncPcaItemsAction.php`, `app/Actions/Pncp/SyncPncpOrgaoUnidadesAction.php` |
| Ciclo e contador | `app/Models/Pca/PncpPcaCiclo.php`, `app/Integrations/PNCP/PncpSyncCycleTracker.php` |
| Leitura da API | `contratacoes/routes/api.php` (`/api/v1/pca/items`) |
