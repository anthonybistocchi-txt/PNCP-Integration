# Integração PNCP — Documentação Técnica do Pipeline de Sincronização

> Documento gerado a partir da leitura do código em 2026-09-25 e atualizado
> na separação dos serviços (2026-09-29).
>
> Há dois apps: `contratacoes/` (API, sync de contratações e documentos) e
> `pca/` (sync de órgãos, unidades e PCA). O Postgres é um só, banco `pncp`.
> Cada serviço tem o próprio Redis. O termo "listagem" foi aposentado: a fase 1
> chama-se contratações. Caminhos abaixo que ainda digam o desenho antigo de
> uma fila única estão superados por esta nota e pelo README.

---

## 1. Visão geral da arquitetura

### Objetivo e problema resolvido

O serviço mantém um **espelho local, consultável e enriquecido** dos dados
públicos do PNCP (Portal Nacional de Contratações Públicas): contratações
publicadas/atualizadas, seus documentos (editais/anexos), os órgãos
compradores, as unidades administrativas desses órgãos e os itens de PCA
(Plano de Contratações Anual).

O problema que ele resolve: a API pública do PNCP é lenta, instável e
rate-limited (o próprio código documenta outages de horas, respostas de
27–29s, erros de pool em ~49s e um WAF que dispara 429 por rajada). Nenhum
usuário final poderia consultar o PNCP diretamente com UX aceitável. A
solução é inverter a responsabilidade: **o PNCP é consumido em background,
no ritmo que ele tolera, e a aplicação lê apenas o PostgreSQL local.**

### Papéis

| Componente | Papel |
|---|---|
| **PNCP** | Fonte upstream (origem dos fatos). Nunca é chamado em request de usuário. |
| **PostgreSQL 15** | **Fonte de verdade para todas as leituras da API.** Também guarda as tabelas de auditoria/controle de sincronização. |
| **Redis** | Fila (`QUEUE_CONNECTION=redis`), cache, circuit breaker, throttle global, locks de unicidade de jobs e travas de modalidade. |
| **Scheduler** (`php artisan schedule:work`) | Dispara os comandos nos horários de `contratacoes/routes/console.php`. |
| **Workers** (3 containers idênticos) | `queue:work redis --sleep=3 --tries=5 --timeout=900 --memory=512 --max-jobs=50 --max-time=3600` (ver `docker-compose.yml`). |

### Síncrono vs. assíncrono

- **Síncrono (leitura):** `contratacoes/routes/api.php` → FormRequest → DTO → Controller → Action → Service → Repository → PostgreSQL. Jamais toca no PNCP. Endpoints: `/api/v1/contratacoes` (QUERY), `/api/v1/contratacoes/relevant/*`, `/api/v1/pca/items`, `/api/v1/orgaos`.
- **Assíncrono (escrita/ingestão):** Scheduler → Command → Job mestre → Jobs operários → `PncpClient` → Action de upsert → PostgreSQL. É o único caminho que fala com o PNCP.

### Visão macro real (corrigida em relação à visão proposta)

```
PNCP
 ├─→ contratações (Fase 1, diária 00:45, serviço contratacoes)
 │     └─→ documentos (Fase 2, automática ao fim de cada modalidade)
 │           └─→ drenagem contínua de pendentes (a cada 20 min) ← caminho principal
 ├─→ reconciliação de contratações (sáb/dom 02:00, reprocessa gaps)
 ├─→ importação de órgãos (diária 18:00, zero HTTP — deriva de contratacoes)
 ├─→ descoberta de unidades (diária 20:00, HTTP PCA + enriquecimento geo local)
 └─→ sincronização do PCA (diária 12:30, depende dos órgãos ativos)
       └─→ tudo disponibilizado para a API (PostgreSQL = fonte de verdade)
```

Duas correções importantes em relação ao fluxo hipotético do pedido:

1. **A busca periódica de documentos não é um subproduto da reconciliação** —
   ela é contínua (a cada 20 min) e é hoje o *caminho principal* dos
   documentos; a "Fase 2" disparada ao fim da listagem é o caminho otimista.
2. **O PCA não depende das unidades** (`unidades_orgao`). Depende apenas
   da allowlist `orgaos`. A tabela de unidades é enriquecimento
   opcional/lateral (comentário em `DiscoverPncpUnidadesCommand` e em
   `routes/console.php`).

---

## 2. Sincronização das contratações públicas (Fase 1)

**Command:** `pncp:sync-contratacoes` — `contratacoes/app/Console/Commands/SyncContratacoesCommand.php`
**Agenda:** diário 00:45 (`routes/console.php`). 00:45 e não 03:00 porque às
3h o pool de conexões do PNCP satura (todo mundo agenda no mesmo horário) —
comentário no próprio arquivo.

### Endpoint e parâmetros

`PncpClient::getContratacoes()` (`contratacoes/app/Integrations/PNCP/PncpClient.php`)
chama por padrão:

```
GET https://pncp.gov.br/api/consulta/v1/contratacoes/atualizacao
```

(existe também o endpoint `.../publicacao`, selecionável pelo parâmetro
`$porAtualizacao = false`; nenhum chamador atual usa — a sync usa
**atualização**, ou seja, traz o que foi *publicado ou alterado* no
intervalo, o que cobre retificações de editais antigos.)

Parâmetros enviados (`array_filter` remove os nulos):

| Parâmetro PNCP | Origem |
|---|---|
| `dataInicial` / `dataFinal` | `Ymd`. Cron diário: `now()->subDays(1)` até `now()` (ontem→hoje). Flags `--days=N`, ou `--data-inicial/--data-final` absolutas para backfill/reprocessamento. |
| `codigoModalidadeContratacao` | Um job por modalidade; padrão 1–10, ou `--modalidades=4,6,8,9`. |
| `uf`, `codigoMunicipioIbge` | Sempre `null` no fluxo de sync (existem no DTO para a leitura da API). |
| `pagina`, `tamanhoPagina` | 50 = máximo aceito pelo PNCP. |

### Fan-out e paginação

1. `SyncContratacoesJob` (`contratacoes/app/Jobs/Contratacoes/SyncContratacoesJob.php`)
   — **job mestre**, `ShouldBeUnique` com `uniqueId() =
   "{modalidade}:{dataInicial}:{dataFinal}"` e `uniqueFor = 12h`. Isso
   colapsa despachos duplicados do mesmo par modalidade+intervalo (inclusive
   os vindos do reprocessador de gaps).
2. O mestre faz **uma única requisição** (página 1) para descobrir
   `totalPaginas` e `totalRegistros`.
3. Abre o ciclo de controle em dois lugares:
   - `PncpSyncCycleTracker::start()` — contadores em Redis (24h TTL);
   - `CicloContratacaoRecorder::start()` — linha durável em
     `ciclos_contratacao` (Postgres), status `started`.
4. Dispara um `ProcessContratacaoPageJob` por página (1..N), com stagger opcional
   (`pncp.page_stagger_seconds`, default 0 — o limitador global já dita o ritmo).
5. Resposta vazia/204 → `recordEmpty()` grava ciclo com status `empty` e
   encerra (nenhuma página é despachada).

### Transformação e persistência

`ProcessContratacaoPageJob` → `SyncContratacaoAction::handle()`
(`contratacoes/app/Actions/Contratacoes/SyncContratacaoAction.php`):

- Descarta itens sem `numeroControlePNCP`.
- Mapeia campos escalares estratégicos para colunas próprias
  (`orgao_cnpj`, `ano_compra`, `sequencial_compra`, `uf`,
  `codigo_municipio_ibge`, `codigo_modalidade_contratacao`,
  `situacao_compra_id`, `valor_total_estimado` **em centavos**,
  datas de abertura/encerramento/publicação/atualização, `srp`,
  `objeto_compra`) e guarda **o JSON integral em `payload_completo` (jsonb)**.
- `sequencial_compra` tem resolução defensiva
  (`resolveSequencialCompra`): raiz → `documentos[0]` → regex no
  `numeroControlePNCP` (`{cnpj}-1-{seq}/{ano}`). Sem ele a Fase 2 não
  consegue chamar `/arquivos`.
- Persistência: **bulk upsert** único por página (50 registros → 1 query):

```php
Contratacao::upsert($upsertData, ['numero_controle_pncp'], [/* colunas atualizáveis */]);
```

### Identidade e anti-duplicação

- PK de `contratacoes` é a string `numero_controle_pncp` (chave
  natural do PNCP). O upsert faz `ON CONFLICT (numero_controle_pncp) DO UPDATE`.
- **Detalhe crucial de idempotência entre fases:** `documentos` e
  `documentos_sincronizados_em` **não** estão na lista de colunas atualizadas pelo
  upsert da Fase 1. Um reprocessamento de listagem (ex.: backfill) nunca
  apaga documentos já buscados. Linha nova nasce com `documentos = []` e
  `documentos_sincronizados_em = NULL` (pendente).

### Como o sistema sabe o que buscar

Não há watermark incremental por registro: a estratégia é **janela móvel por
data** — o cron pede sempre "ontem até hoje" ao endpoint de *atualização*.
Como o upsert é idempotente, re-listar o que já existe é barato e seguro. O
que ainda não foi sincronizado é detectado **retrospectivamente** pelos gaps
em `ciclos_contratacao` (seção 4), não por comparação registro a registro.

### Encerramento do ciclo

Cada página chama `PncpSyncCycleTracker::markPageDone($syncId, $itens)`.
Quando `done === total` (contadores Redis), a última página:

1. grava `finished` + `itens_processados` em `ciclos_contratacao`
   (`CicloContratacaoRecorder::finish`);
2. emite log `PNCP_CONTRATACOES_FINISHED` com totais e duração;
3. **dispara a Fase 2** (`SyncDocumentosJob`) com delay escalonado de
   `PncpDocumentsDispatchSequencer` — 8 min × posição no dia, para as ~10
   modalidades que terminam juntas não bombardearem `/arquivos` em pico
   (incidente real de 05/09 documentado no código).

### Falhas

- 429 / 5xx / timeout → `PncpFailureHandler` + circuit breaker (seção 12) →
  `release()` com a pausa do breaker + jitter. O contador do ciclo **não**
  avança em release — só em conclusão real ou falha permanente.
- Página que esgota o `retryUntil` (12h) → `failed()` conta a página com 0
  itens; se o ciclo não fechar, marca o ciclo `abandoned` — vira gap
  reprocessável no fim de semana.

---

## 3. Sincronização dos documentos (Fase 2 + drenagem contínua)

### Por que é separada da listagem

Dois motivos arquiteturais claros no código:

1. **Perfis de instabilidade diferentes.** A listagem se recupera em
   minutos; o endpoint `/arquivos` já ficou ~8h fora do ar (05–06/09). Por
   isso o circuit breaker tem escopos separados (`listagem` vs `documentos`)
   e o `retryUntil` é 12h para listagem mas **2h para documentos** — um lote
   de documentos abandonado não perde dado (o registro continua pendente),
   então insistir só queimaria worker.
2. **Volume de requisições.** A listagem é ~244 páginas/dia; documentos são
   **1 requisição por contratação** (~12.200/dia). Acoplar os dois faria a
   fase cara atrasar a fase barata e insubstituível.

### Estado de pendência

`contratacoes.documentos_sincronizados_em` (`NULL` = pendente). Criado em
`2026_09_04_120000_add_documents_tracking_to_contratacoes.php` junto
com `sequencial_compra`.

### Caminho otimista — Fase 2

`SyncDocumentosJob` (disparado automaticamente ao fim de cada ciclo de
listagem, com stagger de 8 min/modalidade):

- Seleciona **só o que este ciclo tocou**: `documentos_sincronizados_em IS NULL`
  + `codigo_modalidade_contratacao = X` + `updated_at >= início do ciclo`
  + `sequencial_compra NOT NULL`, ordenado por `data_publicacao_pncp DESC`,
  **teto de 200** (`pncp.pending_documents_max_per_run`).
  - O recorte por `updated_at` (e não por `data_publicacao_pncp`) é
    deliberado: a listagem usa o endpoint de *atualização*, que traz
    licitações antigas retificadas; o upsert toca `updated_at`, então
    "tocado pelo ciclo" é o recorte exato.
- Trava semântica por modalidade: `PncpSyncCycleTracker::tryStartModalidade()`
  (`Cache::add` atômico, TTL 3h). Se a drenagem (ou outra Fase 2) já está
  ativa naquela modalidade, o job **desiste** (`PNCP_DOCUMENTOS_SKIPPED_COLLISION`)
  em vez de duplicar chamadas HTTP.
- Faz chunk em lotes de 10 (`pncp.documents_lote_size`) e dispara
  `ProcessDocumentoPageJob` por lote.

### Caminho principal — drenagem contínua

**Command:** `pncp:sync-documentos-pendentes` —
`contratacoes/app/Console/Commands/SyncDocumentosPendentesCommand.php`
**Agenda:** `*/20 * * * *`. É o comando "a cada 20 minutos" citado no pedido.

Fluxo técnico:

1. O comando dispara um `SyncDocumentosPendentesJob` **por modalidade**,
   com stagger de 1 min entre elas (`pncp.pending_documents_stagger_minutes`)
   — 10 × 8 min estourariam a janela de 20 min, por isso o stagger aqui é
   pequeno; quem dita o ritmo HTTP é o limitador global.
2. O job mestre (`uniqueId = "docs_pending:{modalidade}"`, `uniqueFor =
   900s` < 20 min para a trava não engolir a janela seguinte; `retryUntil`
   de uma janela só) consulta o banco: `documentos_sincronizados_em IS NULL` +
   modalidade, **sem filtro de data**, mais recentes primeiro, teto de 200.
3. Mesma trava de modalidade da Fase 2 → os dois caminhos nunca rodam juntos
   na mesma modalidade.
4. Despacha `ProcessDocumentoPageJob` em lotes de 10.

### O worker de documentos

`ProcessDocumentoPageJob`:

- **Retentativa não recomeça do zero:** `itemsAindaPendentes()` filtra do
  lote o que já tem `documentos_sincronizados_em` preenchido (cobre retry do próprio
  lote e sobreposição entre ciclos mortos e novos).
- Para cada item: `PncpClient::getArquivos($cnpj, $ano, $sequencial)` →
  `GET /api/pncp/v1/orgaos/{cnpj}/compras/{ano}/{sequencial}/arquivos`.
  - **404 = compra sem anexo** → retorna `[]` (caso normal, não erro).
  - 5xx/timeout propaga → lote inteiro vai para retry (não grava `[]` em
    falha de infraestrutura — não perde anexos em silêncio).
- **Persistência parcial:** em caso de falha no meio do lote, o que já foi
  buscado é gravado antes do release. (Comentário no código: com lote
  atômico e 30% de falha/item, a chance de um lote de 50 fechar era
  0,7^50 ≈ 0,00002% — origem dos `attempt: 31..51` de 15/09.)
- Persistência via `SyncContratacaoDocumentosAction`: transação com
  **um `UPDATE` por registro** (10 por lote) tocando apenas `documentos` +
  `documentos_sincronizados_em` — nunca toca nos campos da Fase 1.
- `failed()` (retryUntil de 2h esgotado): loga
  `PNCP_DOCUMENTS_PHASE_ABANDONED`, conta o lote com 0 itens e libera a
  trava da modalidade. O que sobrou continua pendente e é repescado pela
  janela seguinte da drenagem (≤20 min).

### Dimensionamento (documentado em `config/pncp.php`)

Orçamento do limitador global: 1 req / 2s = 1.800 req/h → 600 por janela de
20 min. ~4 modalidades têm volume real (6, 8, 9, 4): 200 × 4 = 800 — passa
propositalmente do teto "limpo"; o excesso é serializado pelo limitador e a
janela estica. O teto antigo de 2.000 com 2 rajadas/dia nunca convergia para
a modalidade 6 (~5.300 registros/dia contra 4.000 de capacidade).

---

## 4. Reconciliação de listagem (fins de semana)

**Command:** `pncp:sync-contratacoes-faltantes` —
`contratacoes/app/Console/Commands/SyncContratacoesFaltantesCommand.php`
**Agenda:** sábado e domingo 02:00 (tráfego baixo no PNCP, duas chances por
semana).

### Mecânica

1. `CicloContratacao::scopeEmGap()` define ciclo incompleto como:
   - `status = abandoned`, **ou**
   - `status = finished` com `itens_processados < total_anunciado`, **ou**
   - `status = started` há mais de `pncp.retry_until_hours.contratacoes` (12h) —
     ciclo preso/morto sem ter fechado.
2. O comando filtra adicionalmente `total_anunciado - itens_processados > 0`
   e pega combinações **distintas** de (modalidade, data_inicial, data_final).
3. Para cada combinação, redespacha `SyncContratacoesJob` — o **mesmo** job do
   cron diário. O `uniqueId` (modalidade+intervalo) colapsa duplicatas do
   mesmo par; o upsert por `numero_controle_pncp` torna a re-listagem
   idempotente (não duplica, só preenche os faltantes/atualiza os existentes).

### Origem dos valores comparados

- `total_anunciado`: `totalRegistros` devolvido pelo PNCP na página 1,
  gravado no START do ciclo.
- `itens_processados`: soma dos `count($response->data)` de cada página,
  acumulada no Redis pelo `PncpSyncCycleTracker` e gravada no FINISH pelo
  `CicloContratacaoRecorder`.

Importante: `itens_processados` conta **itens recebidos**, não INSERTs — o
upsert pode atualizar registros já existentes, então contagem de INSERT não
mede progresso (o README reforça isso).

### E se a segunda sincronização também falhar

O ciclo reprocessado cria uma **nova linha** em `ciclos_contratacao` (novo
`sync_id`). Se ela também ficar em gap, será encontrada pela próxima
execução de sábado/domingo — o mecanismo é auto-alimentado e não tem limite
de tentativas semanais. Não há alerta ativo além dos logs
(`PNCP_CONTRATACOES_CICLO_ABANDONED`, `PNCP_CONTRATACOES_PAGE_ABANDONED`).

### O que detecta vs. o que não detecta

**Detecta:**
- Páginas perdidas por outage/timeout (ciclo finished abaixo do anunciado).
- Ciclos mortos no meio (abandoned / started preso).
- Intervalos inteiros que falharam no dia (o ciclo existe desde o START).

**Não detecta:**
- **Divergência de conteúdo** — um registro cujo payload mudou no PNCP mas
  cuja página foi processada com sucesso antes da mudança (coberto
  parcialmente pelo fato de a janela diária usar o endpoint de
  *atualização*: a alteração re-entra pela janela do dia seguinte).
- Registros que o PNCP **removeu** ou reclassificou de modalidade (o banco
  local é append/upsert; nada deleta).
- Gaps em dias em que o job mestre morreu **antes** do START (não existe
  linha em `ciclos_contratacao` para aquele par modalidade+intervalo —
  cego a esse modo de falha; mitigado pelo `ShouldBeUnique`/retry do mestre
  e pelo fato de o cron rodar todo dia).
- Diferenças de contagem causadas pelo próprio PNCP anunciar
  `totalRegistros` inconsistente entre a sondagem e as páginas (falso
  positivo de gap — inócuo, pois o reprocessamento é idempotente).

### Por que é reconciliation/reprocessing

É um reconciliador clássico de integração: em vez de confiar que "o pipeline
rodou", o sistema mantém **contadores de intenção vs. execução** persistidos
(duros, em Postgres — o Redis é só o contador em voo) e um processo
separado compara os dois e re-executa o trabalho faltante. É a resposta
correta para uma API externa que falha de forma parcial e intermitente: o
caminho feliz não precisa ser perfeito porque o sistema audita a si mesmo e
fecha as lacunas depois, em horário de baixo tráfego.

---

## 5. Pipeline de órgãos

**Command:** `pncp:import-orgaos` —
`pca/app/Console/Commands/ImportPncpOrgaosCommand.php`
**Agenda:** diário 15:00 (antes do `pncp:sync-pca`? **Não** — ver nota de
inconsistência na seção 9: o PCA roda 12:30, *antes* da importação das 15:00).

### Fluxo real (diferente do hipotético — sem tabela intermediária de CNPJs)

```
contratacoes
  → DISTINCT ON (orgao_cnpj) com metadados do payload     [OrgaoRepository::distinctOrgaosFromContratacoes]
  → filtra CNPJs manuais, classifica novo/atualizado
  → upsert em lotes de 500 em orgaos                 [OrgaoRepository::upsertMany]
  → orgaos = allowlist de órgãos ativos
```

Não existe tabela de staging de CNPJs: a extração, deduplicação e carga
acontecem num único comando, **sem nenhuma chamada HTTP** — `razao_social`,
`poder` e `esfera` são extraídos do `payload_completo->orgaoEntidade` da
contratação mais recente daquele CNPJ (ordenado por
`data_atualizacao_global DESC NULLS LAST`).

### Regras de proteção

- `importado_de = 'manual'`: nunca sobrescrito nem desativado pelo import
  (permite cadastrar órgãos que ainda não apareceram em contratações).
- `ativo`: fora do update do upsert — desativar um órgão é decisão humana,
  o import não reativa.
- Unique em `orgaos.cnpj` garante idempotência.

### Para quê serve

`orgaos` é a **allowlist que alimenta o sync de PCA** (e a descoberta
de unidades): `OrgaoRepository::listActive()` é o ponto de partida de
`pncp:sync-pca` e `pncp:discover-unidades`.

---

## 6. Unidades dos órgãos

**Command:** `pncp:discover-unidades` —
`pca/app/Console/Commands/DiscoverPncpUnidadesCommand.php`
**Job:** `DiscoverPncpUnidadesJob` (`pca/app/Jobs/Pncp/DiscoverPncpUnidadesJob.php`)
**Agenda:** diário 16:00 — **apesar de** o comentário em `routes/console.php`
e o README dizerem que não é agendado (inconsistência, seção 17).

### O que é uma unidade neste contexto

Uma unidade administrativa de um órgão **que possui PCA publicado no ano**.
A fonte é o endpoint de PCA, não um cadastro de unidades:

```
GET /api/pncp/v1/orgaos/{cnpj}/pca/{ano}/consolidado/unidades
```

(`PncpClient::getPcaUnidades()` — paginado, 50/página, agrega todas as
páginas no client; 404 devolve o acumulado.)

### Fluxo

1. O comando lista órgãos ativos; pula os que já têm unidades no ano
   (`PncpOrgaoUnidadeRepository::existsForOrgaoAno`), salvo `--force`.
2. O job busca as unidades no PNCP (escopo de breaker `pca`).
3. **Enriquecimento geo sem HTTP extra:** o endpoint de unidades não envia
   UF/IBGE/município. O job extrai esses campos de
   `contratacoes.payload_completo->unidadeOrgao` com um
   `DISTINCT ON (codigoUnidade)` do registro mais recente do mesmo CNPJ
   (`geoFromContratacoes()`). Unidade que nunca apareceu numa contratação
   fica com geo `null`.
4. `SyncPncpOrgaoUnidadesAction`: upsert transacional em
   `unidades_orgao`, unique `(cnpj_orgao, ano_pca, codigo_unidade)`.

### Relação real entre as entidades

```
orgaos (cnpj) ──< unidades_orgao (cnpj_orgao FK → orgaos.cnpj, cascade)
contratacoes.orgao_cnpj ──(sem FK)──> orgaos.cnpj
contratacoes.payload_completo->unidadeOrgao->codigoUnidade ──(sem FK)──> unidades_orgao.codigo_unidade
```

Ou seja: **órgão → unidade** é FK real; **contratação → órgão/unidade** é
relação lógica por CNPJ/codigoUnidade, sem constraints. A localização da
unidade (UF, IBGE, município) vem das contratações, não da API de unidades.

### Papel no pipeline

**Nenhum bloqueante.** O sync de PCA obtém `codigoUnidade`/`nomeUnidade` do
próprio endpoint `/consolidado/unidades` e grava em `planos_pca`.
`unidades_orgao` é um inventário lateral para cruzamentos futuros
(PCA ↔ contratação ↔ unidade), conforme o docblock do comando.

---

## 7. PCA — Plano de Contratações Anual

**Command:** `pncp:sync-pca` — `pca/app/Console/Commands/SyncPcaCommand.php`
**Agenda:** diário 12:30 (horário separado da listagem das 00:45 para não
competir pelo limitador global).

### Por que depende dos órgãos

O PNCP não oferece um endpoint de listagem global de PCAs por data — o PCA é
consultado **por órgão** (`/orgaos/{cnpj}/pca/...`). Logo, o universo de
sincronização é `orgaos` com `ativo = true`. Sem a allowlist importada
das contratações (seção 5), não haveria como saber quais CNPJs consultar.

### Cadeia de jobs

```
SyncPcaCommand (por órgão ativo)
  → SyncPcaDataJob            (mestre do órgão; unique "{ano}:{cnpj}", 12h)
      → GET /orgaos/{cnpj}/pca/{ano}/consolidado/unidades   (paginado no client)
      → monta PcaPlanData por sequencialPca
      → SyncPcaPlanAction: bulk upsert em planos_pca (unique cnpj+ano+sequencial)
      → grava linha em ciclos_pca (nível órgão, sem sequencial)
      → por plano: SyncPcaItemsJob   (sub-mestre do plano)
          → GET .../{sequencial}/itens/quantidade   (dimensiona a paginação)
          → cycleTracker.start + PncpPcaCiclo::create (nível plano, com sequencial)
          → por página: ProcessPcaItemsPageJob
              → GET .../{sequencial}/itens?pagina=N&tamanhoPagina=50
              → resolve plano_pca_id (release(30) se o plano ainda não commitou)
              → PcaItemData → SyncPcaItemsAction: bulk upsert em itens_pca
                (unique plano_pca_id + numero_item)
              → markPageDone → última página fecha o ciclo em ciclos_pca
```

Observações de implementação:

- O endpoint de itens **não tem envelope de paginação** (devolve array puro);
  por isso existe a chamada separada `itens/quantidade` para dimensionar o
  fan-out.
- `ciclos_pca` recebe **duas granularidades** de linha: uma por órgão
  (criada pelo mestre, `sequencial_pca` nulo) e uma por plano (criada pelo
  sub-mestre). Serve como auditoria; **não existe** reprocessador de gaps de
  PCA equivalente ao `pncp:sync-contratacoes-faltantes` — a cura é a reexecução
  diária idempotente do `pncp:sync-pca` (os upserts convergem o estado).
- O flag `--force` do comando foi **removido** — era transportado pelo `PcaSyncParamsDTO`, mas
  **nunca lido** por `SyncPcaDataJob` (não existe verificação de "ciclo em
  andamento" no lado do PCA). Foi no-op e a opção foi removida da interface.
- `PncpClient::getPcaConsolidado()` e `getPcaValorCategoria()` existem mas
  **não têm chamadores** — código preparado para uso futuro.

### Tabelas do PCA

- `planos_pca` — um PCA por (cnpj_orgao, ano_pca, sequencial_pca); guarda
  unidade (código/nome), situação, datas e `payload_completo` jsonb.
- `itens_pca` — itens do plano; FK `plano_pca_id → planos_pca.id` (cascade);
  unique `(plano_pca_id, numero_item)`; ~24 colunas de domínio (categoria,
  catálogo/PDM, descrição, quantidades/valores em `numeric`, data desejada,
  unidade requisitante, grupo de contratação, datas) **+ coluna gerada
  `search_vector tsvector STORED`** com índice GIN (FTS em português sobre
  descrição, classificação, grupo, unidade requisitante e PDM).
- `ciclos_pca` — auditoria dos ciclos (sync_id, cnpj, ano, sequencial,
  anunciado/processado, status started/finished/empty/abandoned).

### Histórico e atualizações

Não há versionamento: o upsert sobrescreve o estado atual do plano/item
(campos mutáveis atualizam; a chave natural impede duplicidade). O histórico
implícito fica em `payload_completo` (última versão) e nas datas
`data_inclusao`/`data_atualizacao` vindas do PNCP. Reexecutar o sync do ano
é seguro e converge o banco ao estado atual do PNCP.

### Disponibilização

`QUERY|GET /api/v1/pca/items` → `ListPcaItemsRequest` → `ListPcaItemsDTO` →
`ListPcaItemsController` → `ListPcaItemsAction` → `PcaItemsService` →
`PcaItemRepository::list()` — join `itens_pca ⨝ planos_pca`, filtros por
ano/cnpj/categoria, FTS com `websearch_to_tsquery('portuguese', …)` +
`ts_rank` quando `search` presente, e **keyset pagination** por
`(data_publicacao_pncp DESC, id ASC)` com cursor Base64-URL. Resposta via
`PcaItemResource`.

---

## 8. Dependências entre os processos (DAG real)

```
                        ┌─────────────────────────────────────────────┐
                        │            pncp:sync (00:45)                │
                        │      1 SyncContratacoesJob por modalidade       │
                        └──────────────────┬──────────────────────────┘
                                           │ fan-out por página
                                           ▼
                              ProcessContratacaoPageJob ──upsert──► contratacoes
                                           │ (última página do ciclo)
                                           ▼
                              SyncDocumentosJob (Fase 2, delay 8min×pos)
                                           │
                                           ▼
                              ProcessDocumentoPageJob ──update──► documentos

  pncp:sync-documentos-pendentes (*/20min) ──► SyncDocumentosPendentesJob
        └──────────────────────────────────────► ProcessDocumentoPageJob
        (mesma trava de modalidade da Fase 2; nunca concorrentes)

  pncp:sync-contratacoes-faltantes (sáb/dom 02:00)
        lê ciclos_contratacao (em gap) ──► redespacha SyncContratacoesJob

  contratacoes ──► pncp:import-orgaos (15:00) ──► orgaos
                                                                  │
        ┌─────────────────────────────────────────────────────────┤
        ▼                                                         ▼
  pncp:discover-unidades (16:00)                        pncp:sync-pca (12:30)
  → DiscoverPncpUnidadesJob                             → SyncPcaDataJob
  → GET pca/{ano}/consolidado/unidades                  → SyncPcaItemsJob
  → + geo de contratacoes                       → ProcessPcaItemsPageJob
  → unidades_orgao                                 → planos_pca / itens_pca
```

Dependências duras:

| Processo | Depende de |
|---|---|
| Fase 2 (documentos do ciclo) | Ciclo de listagem da mesma modalidade ter fechado |
| Drenagem de pendentes | Existirem registros com `documentos_sincronizados_em IS NULL` |
| Reconciliação | Linhas em `ciclos_contratacao` |
| Import de órgãos | `contratacoes` populada |
| Discover unidades | `orgaos` ativos + contratações (geo) |
| Sync PCA | `orgaos` ativos (apenas isso) |
| ProcessPcaItemsPageJob | `planos_pca` commitado pelo mestre (senão `release(30)`) |

Dependência **inexistente** (contrariando a hipótese inicial): o sync de PCA
**não** depende de `unidades_orgao`.

---

## 9. Comandos e agendamento

Fonte da verdade: `contratacoes/routes/console.php` para contratações e documentos, `pca/routes/console.php` para órgãos, unidades e PCA. Cada serviço usa a fila `default` no próprio Redis.

| Comando | Responsabilidade | Frequência | Dispara | Lê | Escreve | API PNCP | Falhas / recuperação |
|---|---|---|---|---|---|---|---|
| `pncp:sync-contratacoes` | Contratações do dia (ontem→hoje), 10 modalidades | Diário 00:45 | `SyncContratacoesJob` ×N modalidades | — | — (os jobs escrevem) | `contratacoes/atualizacao` | Breaker `contratacoes`; retryUntil 12h; gaps cobertos pela reconciliação |
| `pncp:sync-documentos-pendentes` | Drenagem contínua de documentos pendentes | `*/20 * * * *` | `SyncDocumentosPendentesJob` por modalidade (stagger 1 min) → lotes `ProcessDocumentoPageJob` | `contratacoes` | `contratacoes` (documentos) | `/arquivos` | Breaker `documentos`; retryUntil 2h; pendência persiste → próxima janela |
| `pncp:sync-contratacoes-faltantes` | Reprocessa ciclos de contratação em gap | Sáb/dom 02:00 | `SyncContratacoesJob` (mesmo do cron; unique colapsa dups) | `ciclos_contratacao` | indireto (via jobs) | `contratacoes/atualizacao` | Idempotente por upsert; reexecutável |
| `pncp:import-orgaos` | Allowlist de órgãos (CNPJs únicos + razão social/poder/esfera do payload) | Diário 18:00 | nada (síncrono, zero HTTP) | `contratacoes`, `orgaos` | `orgaos` | nenhuma | `--dry-run`; upsert em lotes de 500; preserva `manual`/`ativo` |
| `pncp:discover-unidades` | Inventário de unidades com PCA no ano (+geo local) | Diário 20:00 | `DiscoverPncpUnidadesJob` por CNPJ | `orgaos`, `contratacoes` (geo) | `unidades_orgao` | `/pca/{ano}/consolidado/unidades` | Breaker `pca`; pula já-descobertos (ou `--force`) |
| `pncp:sync-pca` | Sync do PCA dos órgãos ativos | Diário 12:30 | `SyncPcaDataJob` por órgão → `SyncPcaItemsJob` por plano → `ProcessPcaItemsPageJob` por página | `orgaos` | `planos_pca`, `itens_pca`, `ciclos_pca` | `/pca/{ano}/consolidado/unidades`, `/itens/quantidade`, `/itens` | Breaker `pca`; retryUntil 12h; sem reconciliador próprio (cura pela reexecução diária) |

Todos os comandos também podem ser executados manualmente (`php artisan`),
com opções de recorte (`--modalidades`, `--data-inicial/--data-final`,
`--cnpj`, `--ano`, `--dry-run`).

Nenhum comando usa filas dedicadas: tudo vai para a fila `default` do Redis,
consumida pelos 3 workers. A coordenação de concorrência é feita por
`ShouldBeUnique` (mestres), trava de modalidade (documentos) e stagger.

---

## 10. Banco de dados

### `contratacoes` (definitiva — núcleo)

- **PK:** `numero_controle_pncp` (string natural do PNCP; model com
  `incrementing = false`).
- **Campos próprios:** `orgao_cnpj`, `ano_compra`, `sequencial_compra`,
  `data_publicacao_pncp`, `data_atualizacao_global`, `uf`,
  `codigo_municipio_ibge`, `codigo_modalidade_contratacao`,
  `situacao_compra_id`, `valor_total_estimado` (**bigint em centavos** —
  accessor/mutator no model converte), `data_abertura_proposta`,
  `data_encerramento_proposta`, `srp`, `objeto_compra` (text).
- **JSONB:** `payload_completo` (registro integral), `documentos` (Fase 2).
- **Controle de sync:** `documentos_sincronizados_em` (NULL = pendente).
- **Índices:** B-tree simples nas colunas de filtro; composto parcial
  `(uf, modalidade, data_publicacao_pncp DESC) WHERE situacao_compra_id = 1`;
  `(data_atualizacao_global DESC)`; **keyset**
  `(data_publicacao_pncp DESC, numero_controle_pncp ASC)`; GIN FTS
  `to_tsvector('portuguese', objeto_compra)`; índices de expressão sobre o
  JSONB (`poderId`, `esferaId`, `modoDisputaId`,
  `tipoInstrumentoConvocatorioCodigo`, GIN `jsonb_path_ops` em
  `fontesOrcamentarias`).
- **Escreve:** Fase 1 (upsert), Fase 2/drenagem (update de documentos).
- **Lê:** API de contratações, import de órgãos, geo das unidades,
  seleção de pendentes.

### `ciclos_contratacao` (controle/auditoria)

`sync_id` (uuid unique), `modalidade`, `data_inicial`/`data_final` (char 8),
`total_anunciado`, `total_paginas`, `itens_processados`,
`paginas_completas`, `status` (`started|finished|empty|abandoned`),
`started_at`/`finished_at`. Índice `(status, data_inicial, modalidade)`.
Escrita por `CicloContratacaoRecorder`; leitura por
`pncp:sync-contratacoes-faltantes` (scope `emGap`).

### `orgaos` (definitiva — allowlist)

`cnpj` unique, `razao_social`, `poder`/`esfera` (char 1 — letras, não IDs),
`ativo` (bool, indexado), `importado_de` (`contratacoes|manual`).
Escrita: `pncp:import-orgaos` (+ cadastro manual). Leitura: `sync-pca`,
`discover-unidades`.

### `unidades_orgao` (definitiva — enriquecimento lateral)

Unique `(cnpj_orgao, ano_pca, codigo_unidade)`; FK
`cnpj_orgao → orgaos.cnpj` (cascade). Campos da API de unidades +
`uf_sigla`/`codigo_ibge`/`nome_municipio` enriquecidos das contratações
(nullable) + `payload_completo`. Índices: `(cnpj_orgao, ano_pca)`, `uf_sigla`,
`codigo_ibge`.

### `planos_pca` (definitiva)

Unique `(cnpj_orgao, ano_pca, sequencial_pca)`; índice `(cnpj_orgao, ano_pca)`;
`ano_pca` indexado. Unidade (código/nome) desnormalizada do endpoint.
**Sem FK para `orgaos`** (relação lógica por CNPJ).

### `itens_pca` (definitiva)

FK `plano_pca_id → planos_pca.id` (cascade); unique
`(plano_pca_id, numero_item)`; índices `(plano_pca_id, categoria_item_pca)`,
`(plano_pca_id, data_desejada)`, `(plano_pca_id, grupo_contratacao_codigo)`;
**`search_vector` tsvector gerada (STORED) + GIN**. Valores em
`numeric/decimal` (nunca float).

### `ciclos_pca` (controle/auditoria)

Mesmo desenho de `ciclos_contratacao`, com `cnpj_orgao`, `ano_pca`,
`sequencial_pca` nullable. Escrita direta via model nos jobs de PCA (não há
classe Recorder equivalente — inconsistência de padrão). Leitura: nenhum
processo automatizado (só diagnóstico manual).

### Demais tabelas

`users`, `palavras_chave`, `filtros_contratacao` (domínio de usuário:
busca personalizada `/contracts/relevant/*` cruza keywords/filtros salvos
com FTS — `ContratationKeywordFilter`), `failed_jobs`, `jobs` não se aplica
(fila é Redis), `job_batches`.

### Mapa de relacionamentos

```
orgaos 1───< unidades_orgao        (FK real, cascade)
planos_pca   1───< itens_pca                  (FK real, cascade)
orgaos 1───< planos_pca                  (lógica, por cnpj — sem FK)
orgaos 1───< contratacoes       (lógica, por orgao_cnpj — sem FK)
contratacoes >─── unidades_orgao (lógica, cnpj + codigoUnidade via payload)
```

---

## 11. Estratégia de idempotência

| Operação | Mecanismo | Seguro reexecutar? |
|---|---|---|
| Upsert contratações (Fase 1) | PK natural `numero_controle_pncp` + lista de colunas atualizáveis que **exclui** `documentos`/`documentos_sincronizados_em` | Sim — ilimitado |
| Update de documentos (Fase 2/drenagem) | UPDATE por PK só nas 2 colunas; `itemsAindaPendentes()` pula o que já foi | Sim |
| Import de órgãos | Unique `cnpj`; update não toca `ativo`/`importado_de`; manuais ignorados | Sim (diário) |
| Unidades | Unique `(cnpj_orgao, ano_pca, codigo_unidade)` + transação | Sim (`--force`) |
| PCA plans | Unique `(cnpj_orgao, ano_pca, sequencial_pca)` | Sim (diário) |
| PCA items | Unique `(plano_pca_id, numero_item)` | Sim |
| Ciclo de listagem | `updateOrCreate` por `sync_id` (uuid único) | n/a (interno) |
| Job mestre listagem | `ShouldBeUnique` por modalidade+intervalo, 12h | Despachos duplicados colapsam |
| Job mestre documentos (Fase 2) | `ShouldBeUnique` `docs:{mod}:{di}:{df}`, 2h + trava de modalidade | Sim |
| Drenagem | `ShouldBeUnique` `docs_pending:{mod}`, 15 min + trava de modalidade | Sim |
| Job mestre PCA | `ShouldBeUnique` `{ano}:{cnpj}`, 12h | Sim |
| ProcessPcaItemsPageJob | Sem unique lock; idempotência pelo upsert; `release(30)` se plano ausente | Sim |

**Sem risco de duplicação identificado** nos caminhos de escrita — todos
ancoram em chave natural com `ON CONFLICT DO UPDATE`. O ponto mais frágil é
temporal, não de duplicidade: dois ciclos de documentos sobre os mesmos
registros (mitigado pela trava de modalidade e pelo filtro de pendentes no
worker).

---

## 12. Resiliência e tolerância a falhas

### O que existe hoje

**Camada HTTP (`PncpClient`):**
- Throttle global via Redis: `Redis::throttle('pncp:global')->allow(1)->every(2s)->block(30s)`
  — 1 requisição a cada 2s somando **todos os workers e todos os endpoints**
  (1.800 req/h). O WAF do PNCP dispara por rajada; o limitador é "1 vaga por
  janela", não "N por segundo".
- Timeouts separados: connect 5s, listagem 40s, documentos 30s
  (dimensionados por latência observada em produção).
- 2 retentativas HTTP com 3s de espera, `throw: false` (a decisão de erro é
  semântica, por status).
- Classificação: 429 → `PncpRateLimitedException` (faz parse de
  `Retry-After` em segundos ou data HTTP); 5xx → `PncpUnavailableException`;
  `ConnectionException` (cURL 28) → `PncpUnavailableException(408)`; 4xx →
  `RequestException` (erro nosso, permanente). 204 na listagem → `null`
  (vazio legítimo); 404 em `/arquivos` → `[]` (compra sem anexo).

**Circuit breaker (`PncpCircuitBreaker`, Redis do próprio serviço, por escopo
`contratacoes`/`documentos`/`pca`):**
- 429 abre imediatamente (respeita `Retry-After`; senão escala
  [2, 5, 10, 20] min).
- 5xx/timeout só abre após 3 consecutivas (janela de 10 min); escala
  [1, 3, 10, 20] min.
- Half-open com **sondagem exclusiva** (probe lock de 120s): um único job
  testa a API quando a pausa vence; os demais aguardam.
- Válvula de outage prolongado: 3 sondagens falhas seguidas → pausa de 60
  min + `Log::critical('PNCP_PROLONGED_OUTAGE')`.
- Memória de nível de 180 min (não reinicia a escala a cada pausa — bug real
  de 06/09 documentado no código).

**Jobs:**
- `retryUntil` por tempo (não por tentativas), porque `release()` consome
  tentativa: contratações 12h (`retry_until_hours.contratacoes`), documentos 2h, PCA 12h (`retry_until_hours.pca`).
- `backoff` ([120, 300, 600, …]) reservado a falhas **não-PNCP** (deadlock,
  banco). Falha de API vira `release()` com a pausa do breaker + jitter
  (resume 5–45s / contend 30–90s — evita thundering herd ao fim da pausa).
- `JobExceptionPolicy`: transitório (conexão, 429/5xx, deadlock, PDO) →
  rethrow p/ retry; permanente (4xx) → `fail()`; desconhecido → critical +
  `fail()`.
- `failed()` em todos os jobs-página: loga abandono com contexto e fecha a
  contabilidade do ciclo (0 itens), liberando travas.
- Timeout do worker de documentos (900s) e `documents_lote_size` (10) são
  dimensionados juntos contra `REDIS_QUEUE_RETRY_AFTER=960` — comentários no
  código obrigam refazer a conta ao mexer em qualquer um dos três.

**Controle de ciclo:**
- Redis (`PncpSyncCycleTracker`) para contadores em voo e trava de
  modalidade; Postgres (`ciclos_contratacao`, `ciclos_pca`) para
  auditoria durável e detecção posterior de gaps.
- Persistência parcial em lotes de documentos (falha não descarta o que já
  veio).

**Logs estruturados:** `PNCP_CONTRATACOES_STARTED/FINISHED/PAGE_ABANDONED`,
`PNCP_DOCUMENTOS_STARTED/EMPTY/FINISHED/SKIPPED_COLLISION`,
`PNCP_DOCUMENTS_(PENDING_)PHASE_ABANDONED`, `PNCP_RATE_LIMITED`,
`PNCP_UPSTREAM_UNAVAILABLE`, `PNCP_BREAKER_OPENED/CLOSED`,
`PNCP_PROLONGED_OUTAGE`, `PNCP_UPSERT_DB_ERROR`,
`PNCP_CONTRATACOES_CICLO_ABANDONED/FINISH_MISSED`, `PNCP_PCA_*`,
`PNCP_UNIDADES_*`, `JOB_TRANSIENT/PERMANENT/CRITICAL_ERROR`,
`API_READ_SUCCESS`.

### O que NÃO existe hoje (não confundir)

- Sem alertas ativos (e-mail/Slack) — só logs. (`pncp:monitorar-documentos-pendentes`
  e `pncp:listagem-gaps` constam no README mas **não existem no código**.)
- Sem reconciliador de PCA (equivalente ao de listagem).
- Sem reconciliador de documentos por contagem (a cura é a pendência
  permanecer visível para a drenagem).
- Sem métricas agregadas (Prometheus etc.) — só `pg_stat_statements` para o banco.
- Sem DLQ além da tabela `failed_jobs` padrão do Laravel.

---

## 13. Performance

### Requisições ao PNCP (o gargalo por design)

O limitador global (1 req/2s) é o teto real de vazão — decisão deliberada
para não derrubar o PNCP nem disparar o WAF. Consequências:

- Listagem: ~244 páginas/dia ≈ 8 min de limitador — barata.
- Documentos: ~12.200/dia ≈ 6,8h de limitador distribuído nas 72 janelas de
  20 min — dominante.
- PCA: N órgãos × (1..k unidades + 1 quantidade + páginas de itens por
  plano). Compete pelo mesmo limitador; por isso roda às 12:30, longe da
  listagem das 00:45.

### Banco de dados

**Pontos fortes:**
- Bulk upsert em todos os caminhos de ingestão (50/página, 500/lote de
  órgãos, lote inteiro por plano de PCA).
- Keyset pagination nos dois endpoints de leitura (sem OFFSET em tabela
  grande), com índice composto dedicado em `contratacoes`.
- Índice parcial para o filtro quente (`situacao_compra_id = 1`), índices de
  expressão sobre JSONB, GIN para FTS e para contenção JSON
  (`jsonb_path_ops`), coluna `tsvector` gerada em `itens_pca`.
- `pg_stat_statements` habilitado; Postgres tunado (`postgresql.conf`
  próprio, shm 2gb para paralelismo).
- Leituras nunca competem com o PNCP; escrita de sync é fora do request.

**Pontos de atenção:**
1. **Seleção de pendentes sem índice dedicado.** A query da drenagem
   (`WHERE documentos_sincronizados_em IS NULL AND codigo_modalidade_contratacao = ?
   ORDER BY data_publicacao_pncp DESC LIMIT 200`) roda 720×/dia (72 janelas
   × 10 modalidades). Hoje depende dos índices existentes (modalidade ou
   keyset). Um índice parcial
   `(codigo_modalidade_contratacao, data_publicacao_pncp DESC)
   WHERE documentos_sincronizados_em IS NULL` eliminaria o sort/filtro residual —
   e ele *encolhe sozinho* à medida que a drenagem avança.
2. **`updated_at >= cycleStartedAt` sem índice** na Fase 2 — mitigado pelo
   conjunto já estar filtrado por modalidade + pendência, mas visível em
   dias de backfill grande.
3. **Contagens da paginação.** Tanto `ContratacaoRepository` quanto
   `PcaItemRepository` executam até 3 queries por página: `count` total,
   `count` de "skipped" (para inferir o número da página) e a página em si.
   Os counts são O(resultado do filtro) e o de skipped cresce com a
   profundidade da página. Se `count`/`page` não forem contrato rígido do
   frontend, dá para eliminar ou cachear.
4. **Cursor do PCA com busca textual.** Com `search`, a ordenação vira
   `rank DESC, data_publicacao_pncp DESC, id ASC`, mas o predicado do cursor
   só conhece `(data_publicacao_pncp, id)` — a chave de ordenação efetiva e
   a chave do cursor divergem, podendo pular/repetir itens entre páginas em
   buscas textuais. Sem `search`, está correto.
5. **`itens_pca` sem índice para a ordenação do cursor**
   `(data_publicacao_pncp DESC, id ASC)` — ao contrário de
   `contratacoes`, que tem `idx_contratations_keyset_cursor`. Em
   volume, a página vira sort.
6. **Update de documentos é N statements** dentro de transação (10 por lote)
   — aceitável no lote atual; se o lote crescer, considerar `UPDATE … FROM
   (VALUES …)`.
7. `distinctOrgaosFromContratacoes()` e `geoFromContratacoes()` fazem
   `DISTINCT ON` sobre jsonb em varredura potencialmente ampla — com índice
   em `orgao_cnpj` o custo é administrável, mas cresce com o histórico.

### Concorrência

3 workers × `--max-jobs=50 --max-time=3600`, fila única `default`. A
concorrência HTTP efetiva não é a dos workers, é a do limitador global.
Travas de unicidade e de modalidade evitam trabalho duplicado. O stagger
(8 min na Fase 2, 1 min na drenagem, `page_stagger_seconds` opcional) espalha
picos de despacho.

---

## 14. Fluxo completo de ponta a ponta (narrativa operacional)

1. **00:45** — o scheduler dispara `pncp:sync`. Para cada uma das 10
   modalidades, um `SyncContratacoesJob` entra na fila Redis (único por
   modalidade+intervalo por 12h).
2. Cada mestre pergunta ao PNCP "o que mudou entre ontem e hoje nesta
   modalidade?" (página 1). Antes de chamar, verifica o circuit breaker do
   escopo `listagem`; toda chamada passa pelo limitador global de 1 req/2s.
3. Com `totalPaginas` em mãos, o mestre abre o ciclo (Redis +
   `ciclos_contratacao` = `started`) e enfileira um `ProcessContratacaoPageJob`
   por página.
4. Cada página busca 50 registros e faz um único bulk upsert em
   `contratacoes`. Registro novo nasce pendente de documentos;
   registro existente é atualizado **sem tocar** em documentos já buscados.
5. A última página fecha o ciclo (`finished` + contadores) e agenda a Fase 2
   daquela modalidade com 8 min × posição do dia de delay.
6. A Fase 2 tenta pegar a trava da modalidade, seleciona até 200 registros
   que **este ciclo** tocou e ainda estão pendentes, e despacha lotes de 10.
7. Em paralelo, **a cada 20 min**, a drenagem varre cada modalidade por
   pendências de **qualquer data** (teto 200, mais recentes primeiro) e
   despacha os mesmos lotes de 10. Fase 2 e drenagem nunca rodam juntas na
   mesma modalidade (trava). Cada lote chama `/arquivos` por contratação,
   persiste parcialmente em caso de falha e marca `documentos_sincronizados_em`.
   O que falhar fica pendente e volta na janela seguinte.
8. **Sábado e domingo 02:00**, a reconciliação lê `ciclos_contratacao`,
   encontra ciclos abandonados/finished-abaixo-do-anunciado/started-presos e
   redespacha o mesmo `SyncContratacoesJob` para os intervalos afetados. O
   upsert garante que só os faltantes sejam inseridos de fato.
9. **15:00** — `pncp:import-orgaos` extrai os CNPJs únicos de
   `contratacoes` (com razão social/poder/esfera do payload mais
   recente) e faz upsert na allowlist `orgaos`. Zero HTTP.
10. **16:00** — `pncp:discover-unidades` busca as unidades com PCA do ano
    para cada órgão ativo e enriquece UF/IBGE/município a partir das
    contratações locais.
11. **12:30** — `pncp:sync-pca` despacha um mestre por órgão ativo. O mestre
    lista os planos do ano (`/consolidado/unidades`), grava em `planos_pca` e
    abre um sub-mestre por plano, que mede `itens/quantidade`, pagina
    `/itens` de 50 em 50 e grava em `itens_pca` (bulk upsert por página).
    Cada ciclo é auditado em `ciclos_pca`.
12. A qualquer momento, a API atende `/api/v1/contracts*` e
    `/api/v1/pca/items` **somente do PostgreSQL**, com filtros, FTS em
    português e paginação por cursor.

Se o PNCP cair no meio de qualquer etapa: o breaker abre (por escopo), os
jobs esperam com release+jitter dentro do seu `retryUntil`, a sondagem
half-open testa a volta, e o que mesmo assim expirar vira gap auditado
(listagem) ou pendência persistente (documentos) — ambos com caminho de
recuperação automática.

---

## 15. Diagrama da arquitetura real

```
                              ┌──────────────────────────────┐
                              │             PNCP             │
                              │  /contratacoes/atualizacao   │
                              │  /orgaos/{cnpj}/compras/...  │
                              │  /orgaos/{cnpj}/pca/...      │
                              └──────────────┬───────────────┘
                                             │ HTTPS (1 req/2s global,
                                             │  circuit breaker por escopo)
                                             ▼
                              ┌──────────────────────────────┐
                              │   PncpClient (Integrations)  │
                              └──────────────┬───────────────┘
                                             │
        ┌──────────────────┬─────────────────┼──────────────────┬─────────────────────┐
        │                  │                 │                  │                     │
        ▼                  ▼                 ▼                  ▼                     ▼
  pncp:sync          Fase 2 auto      drenagem */20min   reconciliação        pncp:sync-pca
  (00:45)            (fim de ciclo,   pendentes          (sáb/dom 02:00)      (12:30)
  SyncContratacoesJob     stagger 8min)   SyncPncpPending    relê ciclos em gap   SyncPcaDataJob
        │             SyncPncpDocsJob   DocumentsJob     → SyncContratacoesJob         │
        ▼                  │                 │                                     ▼
  ProcessContratacaoPageJob       └───────┬─────────┘                              SyncPcaItemsJob
        │ upsert                   ▼                                              │
        │                  ProcessDocumentoPageJob (lotes de 10)               ▼
        ▼                          │ update docs                          ProcessPcaItemsPageJob
 ┌──────────────────┐              ▼                                              │ upsert
 │contratacoes│◄───────────┴─────────────────────┐                        ▼
 └────────┬─────────┘                                    │                 ┌────────────┐
          │ DISTINCT ON (orgao_cnpj)                     │                 │ planos_pca  │──<┌───────────┐
          ▼                                              │                 └─────┬──────┘   │ itens_pca │
  pncp:import-orgaos (15:00)                             │                       │ FK       └───────────┘
          ▼                                              │                       │
 ┌──────────────────┐                                    │                 ciclos_pca (auditoria)
 │   orgaos    │                                    │
 └────────┬─────────┘                                    │
          │                                              │
          ▼                                              │
  pncp:discover-unidades (16:00)                         │
          │  + geo do payload das contratações ──────────┘
          ▼
 ┌────────────────────────┐         ┌───────────────────────────────────────────┐
 │  unidades_orgao   │         │  API (síncrona, somente PostgreSQL):      │
 └────────────────────────┘         │  QUERY /api/v1/contracts                  │
                                    │  GET  /api/v1/contracts/relevant/*        │
  ciclos_contratacao (auditoria)  │  QUERY|GET /api/v1/pca/items              │
  ► alimenta a reconciliação        └───────────────────────────────────────────┘
```

---

## 16. Glossário (neste projeto)

- **PNCP** — Portal Nacional de Contratações Públicas. API upstream lenta e
  instável; tratada como sistema externo não confiável que dita o ritmo
  (throttle global) e sinaliza pausas (429/Retry-After).
- **Contratação** — linha de `contratacoes`; identidade =
  `numero_controle_pncp`. Espelho local de uma compra pública (pregão,
  dispensa etc.), com payload integral em jsonb.
- **Documento** — edital/anexo de uma contratação, buscado individualmente
  em `/arquivos` e gravado em `documentos` (jsonb). Pendência =
  `documentos_sincronizados_em IS NULL`.
- **Órgão** — entidade compradora (CNPJ raiz). `orgaos` é a allowlist
  que limita o universo do sync de PCA.
- **Unidade** — unidade administrativa de um órgão *que tem PCA no ano*
  (`unidades_orgao`). Não é cadastro geral de unidades.
- **CNPJ** — chave de ligação lógica entre contratações, órgãos, unidades e
  PCA (FK real só existe em `unidades_orgao`).
- **PCA** — Plano de Contratações Anual: intenções de compra do órgão no
  ano. `planos_pca` (um por sequencial/unidade) 1—N `itens_pca`.
- **Sincronização** — cópia assíncrona PNCP → PostgreSQL via filas, sempre
  por upsert idempotente.
- **Reconciliação** — comparação anunciado vs. processado em
  `ciclos_contratacao` e reprocessamento dos intervalos em gap
  (sáb/dom).
- **Reprocessamento** — redespacho do *mesmo* `SyncContratacoesJob` para um
  intervalo em gap; seguro porque a escrita é upsert por chave natural.
- **Idempotência** — propriedade de qualquer sync poder rodar N vezes sem
  duplicar nem corromper (upserts + locks de unicidade + exclusão de
  colunas de outra fase do update).
- **Paginação (PNCP)** — `pagina`/`tamanhoPagina` (máx. 50); o mestre sonda
  a página 1 para descobrir o total e faz fan-out.
- **Paginação (API local)** — keyset/cursor Base64 sobre
  `(data_publicacao_pncp, id)`, sem OFFSET.
- **Retry** — HTTP: 2 tentativas com 3s. Fila: `retryUntil` por tempo (12h
  listagem/PCA, 2h documentos) + `backoff` para falhas não-PNCP.
- **Backoff** — escalas do breaker ([1,3,10,20] min para 5xx; [2,5,10,20]
  para 429) e os arrays `$backoff` dos jobs para falhas de infra local.
- **Fila** — Redis, conexão `default`, consumida por 3 workers idênticos.
- **Job** — unidade de trabalho assíncrona (mestre = descobre e despacha;
  operário/página/lote = executa e persiste).
- **Scheduler** — `schedule:work` rodando `routes/console.php`.
- **Circuit breaker** — pausa global por escopo em Redis (`pncp_cb:{scope}:*`)
  com half-open por sondagem exclusiva e válvula de outage prolongado.
- **Ciclo (sync cycle)** — unidade de auditoria de uma sincronização:
  contadores em Redis enquanto voa, linha durável em Postgres para
  reconciliação posterior.

---

## 17. Diagnóstico final

### O que o sistema já faz bem

1. **Separação leitura/escrita impecável** — a API nunca chama o PNCP;
   PostgreSQL é a fonte de verdade. Padrão em camadas consistente
   (FormRequest → DTO → Action/Service → Repository → Resource).
2. **Resiliência de verdade, nascida de incidentes reais** — throttle
   global anti-rajada, breaker por escopo com sondagem half-open, válvula de
   outage prolongado, jitter anti-thundering-herd, `retryUntil` por tempo.
   Os comentários documentam os incidentes que originaram cada decisão.
3. **Idempotência ponta a ponta** — chaves naturais + upserts + a sutileza
   de a Fase 1 não atualizar colunas da Fase 2.
4. **Auditoria durável e reconciliação** — `ciclos_contratacao` +
   reprocessador de gaps é o padrão correto para integração com API externa
   instável.
5. **Coordenação anti-duplicação de trabalho** — `ShouldBeUnique`, trava de
   modalidade, staggers, `itemsAindaPendentes`.
6. **Engenharia de banco** — keyset pagination, índice parcial, índices de
   expressão em jsonb, GIN/FTS, coluna tsvector gerada, valores monetários
   em centavos/numeric, `pg_stat_statements`.
7. **Dimensionamento explícito** — as contas (teto da drenagem × orçamento
   do limitador; timeout do lote × retry_after da fila) estão escritas em
   `config/pncp.php` e nos jobs.

### Pontos de atenção

1. ~~**Documentação divergente do código.**~~ **Resolvido**: README e
   `integracao-pncp.md` atualizados com horários corretos (import-orgaos 18:00,
   discover-unidades 20:00, sync-pca 12:30), comentário contraditório em
   `routes/console.php` corrigido, e menções a `pncp:monitorar-documentos-pendentes`
   / `pncp:listagem-gaps` (inexistentes) removidas.
2. ~~**Cursor do PCA inconsistente com busca textual**~~ **Resolvido**: `PcaItemRepository`
   agora remove `selectRaw(ts_rank)` e `orderByDesc('rank')` quando `search` está
   presente — ordenação permanece `(data_publicacao_pncp DESC, id ASC)` em todos os casos.
3. ~~**`itens_pca` sem índice para a ordenação do cursor**~~ **Resolvido**: migration
   `2026_09_26_120000_add_keyset_index_to_itens_pca.php` criada.
4. ~~**Query da drenagem sem índice parcial dedicado**~~ **Resolvido**: migration
   `2026_09_26_120001_add_pending_docs_partial_index_to_contratacoes.php` criada.
5. ~~**`--force` do `pncp:sync-pca` é no-op**~~ **Resolvido**: opção `--force`
   removida do comando e do `PcaSyncParamsDTO` (era interface mentirosa).
6. **Sem reconciliador de PCA** — `ciclos_pca` registra gaps, mas nada
   os consome; a cura depende da reexecução diária convergir (o que é
   aceitável, mas cego a falhas permanentes de um único plano, ex.: 400
   persistente em `itens/quantidade`).
7. **`ciclos_pca` escrito via model direto nos jobs** — diverge do
   padrão `CicloContratacaoRecorder`; e a tabela mistura duas
   granularidades (órgão e plano) na mesma coluna `sync_id`.
8. **PCA reusa `retry_until_hours.contratacoes`** — acoplamento de config
   semântica; se a listagem mudar, o PCA muda junto sem aviso.
9. **`DiscoverPncpUnidadesJob` não chama `recordSuccess`** no breaker `pca`
   (não injeta o breaker) — contribui para manter o escopo aberto por mais
   tempo em cenário de recuperação; efeito pequeno porque os jobs de PCA
   registram sucesso.
10. **Sem alerta ativo** — toda a sinalização é log; um gap persistente só é
    descoberto por quem for olhar.
11. **Cegueira a deleções/reclassificações do PNCP** — o espelho local é
    append-only; nada remove o que o PNCP removeu.
12. **`getPcaConsolidado`/`getPcaValorCategoria` sem uso** — código morto
    (ou feature pendente) no client.

### Melhorias recomendadas

| # | Problema | Impacto | Solução | Prioridade | Complexidade | Quando |
|---|---|---|---|---|---|---|
| 1 | ~~README/comentários divergem do scheduler real~~ | ~~Operador toma decisão errada em incidente~~ | **Resolvido**: README, `integracao-pncp.md` e `routes/console.php` atualizados | — | — | **Feito** |
| 2 | ~~Cursor do PCA quebra com `search`~~ | ~~Paginação incorreta em busca textual~~ | **Resolvido**: ordenar por `(data, id)` mesmo com search; rank removido | — | — | **Feito** |
| 3 | ~~Falta índice `(data_publicacao_pncp DESC, id ASC)` em `itens_pca`~~ | ~~Sort em memória nas páginas da API de PCA~~ | **Resolvido**: migration `2026_09_26_120000_add_keyset_index_to_itens_pca` criada | — | — | **Feito** |
| 4 | ~~Drenagem sem índice parcial~~ | ~~Custo fixo 720×/dia crescendo com o passivo~~ | **Resolvido**: migration `2026_09_26_120001_add_pending_docs_partial_index` criada | — | — | **Feito** |
| 5 | ~~`--force` no-op no PCA~~ | ~~Interface mentirosa~~ | **Resolvido**: opção removida | — | — | **Feito** |
| 6 | Sem reconciliador de PCA | Gap de um plano pode persistir até a reexecução diária; falha permanente é invisível | Comando `pncp:sync-pca-faltantes` lendo `ciclos_pca` em gap (espelha o de listagem) | Média | Média | Evolução |
| 7 | Alerta só por log | Descoberta de incidente depende de olhar log | Comando de monitoramento (o `pncp:monitorar-*` que o README promete) emitindo alerta quando pendências/gaps passam de limiar | Média | Média | Evolução |
| 8 | ~~PCA acoplado a `retry_until_hours.contratacoes`~~ | ~~Mudança nas contratações afetava o PCA~~ | **Resolvido**: chave própria `retry_until_hours.pca` | — | — | **Feito** |
| 9 | `ciclos_pca` fora do padrão Recorder | Dois jeitos de fazer a mesma coisa | Extrair `PncpPcaCicloRecorder` (espelhando o de listagem) | Baixa | Baixa | Evolução |
| 10 | Espelho não reflete deleções do PNCP | Registros revogados/anulados permanecem (hoje mitigado por `situacao_compra_id` vir atualizado na re-publicação) | Avaliar marcação de "não visto há N dias" ou sincronização de situação | Baixa | Média | Evolução |
| 11 | Métodos mortos no `PncpClient` | Ruído | Usar (feature de agregações por categoria) ou remover | Baixa | Baixa | Evolução |
| 12 | Contagens extras por página (`count` + skipped `count`) | Custo por página profunda | Se o frontend não precisar de `count`/`page` absolutos, torná-los opcionais | Baixa | Baixa | Evolução |

---

### Apêndice — mapa de arquivos citados

| Camada | Arquivo |
|---|---|
| Scheduler | `contratacoes/routes/console.php` |
| Rotas API | `contratacoes/routes/api.php` |
| Comandos | `contratacoes/app/Console/Commands/{SyncContratacoesCommand, SyncDocumentosPendentesCommand, SyncContratacoesFaltantesCommand, ImportPncpOrgaosCommand, DiscoverPncpUnidadesCommand, SyncPcaCommand}.php` |
| Jobs contratações | `contratacoes/app/Jobs/Contratacoes/{SyncContratacoesJob, ProcessContratacaoPageJob, SyncDocumentosJob, SyncDocumentosPendentesJob, ProcessDocumentoPageJob}.php` |
| Jobs PCA | `pca/app/Jobs/Pca/{SyncPcaDataJob, SyncPcaItemsJob, ProcessPcaItemsPageJob}.php` |
| Job unidades | `pca/app/Jobs/Pncp/DiscoverPncpUnidadesJob.php` |
| Integração | `contratacoes/app/Integrations/PNCP/{PncpClient, PncpCircuitBreaker, PncpSyncCycleTracker, CicloContratacaoRecorder, PncpDocumentsDispatchSequencer}.php` |
| Resiliência | `contratacoes/app/Support/ErrorHandling/{PncpFailureHandler, JobExceptionPolicy, ExceptionClassifier, ApiError}.php` |
| Exceções | `contratacoes/app/Exceptions/Infrastructure/{PncpApiException, PncpRateLimitedException, PncpUnavailableException}.php` |
| Actions sync | `contratacoes/app/Actions/Contratacoes/{SyncContratacaoAction, SyncContratacaoDocumentosAction}.php`, `pca/app/Actions/Pca/{SyncPcaPlanAction, SyncPcaItemsAction}.php`, `pca/app/Actions/Pncp/SyncPncpOrgaoUnidadesAction.php` |
| Leitura | `contratacoes/app/Repositories/{Contratacoes/ContratacaoRepository, Pca/PcaItemRepository}.php` |
| Config | `contratacoes/config/pncp.php`, `contratacoes/config/queue.php` |
| Infra | `docker-compose.yml` (raiz), `contratacoes/.env.example` |
