# Implementação do Módulo PCA — PNCP Integration Backend

## Reconhecimento Concluído

O codebase foi estudado integralmente antes de qualquer proposta. As seções abaixo mapeiam os padrões existentes e descrevem como o PCA será integrado como extensão natural deles.

> [!IMPORTANT]
> **Decisões aprovadas:** Q1 ✅ Q2 (nova estratégia — ver abaixo) ✅ Q3 ✅ Q4 ✅

---

## Arquitetura Atual Mapeada

### Fluxo de Sincronização (contratações)
```
Schedule (console.php)
  ↓ pncp:sync (SyncPncpCommand)
  ↓ SyncPncpDataJob [ShouldBeUnique, retryUntil, backoff]
      → getContratacoes() via PncpClient
      → descobre totalPaginas
      → PncpSyncCycleTracker::start() [Redis]
      → PncpListagemCicloRecorder::start() [PostgreSQL — auditoria durável]
      → Despacha N × ProcessPncpPageJob
        → PncpFailureHandler::shouldWait() [circuit breaker antes de qualquer HTTP]
        → getContratacoes(pagina)
        → SyncPublicContratationAction::handle() [bulk upsert]
        → PncpSyncCycleTracker::markPageDone() [Redis]
        → Quando última página: cicloRecorder::finish() + dispara SyncPncpDocumentsJob
```

### Padrões identificados
| Padrão | Como é usado | Como PCA deve replicar |
|--------|-------------|----------------------|
| **Throttle global** | `Redis::throttle('pncp:global')` na `PncpClient::request()` | Idêntico — mesmo limitador, sem novo namespace |
| **Circuit breaker** | `PncpCircuitBreaker` com SCOPE_LISTAGEM / SCOPE_DOCUMENTOS | Novo escopo `SCOPE_PCA` para não bloquear listagem |
| **retryUntil** | 12h para listagem, 2h para documentos | 12h para PCA (mesmo perfil da listagem) |
| **PncpFailureHandler** | `shouldWait()` + `handle()` em todo job | Reutilizar sem alteração |
| **PncpSyncCycleTracker** | Contador Redis de páginas/lotes em voo | Reutilizar sem alteração |
| **PncpListagemCicloRecorder** | Auditoria durável em `pncp_listagem_ciclos` | Criar `PncpPcaCicloRecorder` análogo ou estender o existente |
| **Bulk upsert** | `Model::upsert()` com chave única | Idêntico |
| **FTS** | `to_tsvector('portuguese', objeto_compra)` + GIN, com `phraseto_tsquery` | Mesma estratégia em `descricao_item` + campos textuais |
| **Cursor pagination** | Base64(JSON({date, numero_controle})), keyset, sem OFFSET | Idêntico, cursor baseado em `(data_publicacao_pncp, id)` |
| **FormRequest → DTO → Service → Repository** | Fluxo de leitura completo | Idêntico |
| **ShouldBeUnique** | Job mestre com `uniqueId` por parâmetros | PCA terá uniqueId por `(ano, cnpj)` ou por `(ano)` |

---

## Decisões de Arquitetura

### Q1 — Circuit Breaker ✅
Novo `SCOPE_PCA = 'pca'` em `PncpCircuitBreaker`. Uma constante, nenhuma lógica nova.

### Q2 — Estratégia de CNPJs (nova decisão) ✅
Tabela dedicada `pncp_orgaos` para CNPJs monitorados. Dois comandos novos:
- **`pncp:import-orgaos`** — varre `public_contratations` e importa CNPJs únicos para a tabela.
- **`pncp:discover-unidades`** — para cada CNPJ na tabela, chama `/consolidado/unidades` no PNCP e salva as unidades em `pncp_orgao_unidades`.

Essa estrutura permite:
- Adicionar/remover CNPJs manualmente sem alterar código.
- Cruzar PCA → contratação → unidade no futuro sem chamadas HTTP adicionais.
- Ter fonte única de verdade sobre os órgãos monitorados.

### Q3 — Estrutura de Jobs ✅
`SyncPcaDataJob` (mestre por cnpj+ano) → `SyncPcaItemsJob` (sub-mestre por sequencial) → `ProcessPcaItemsPageJob` (operário por página).

### Q4 — Auditoria de Ciclos ✅
Nova tabela `pncp_pca_ciclos`, análoga a `pncp_listagem_ciclos`, sem modificar a existente.

---

## Nota sobre Compressão de JSON

> [!IMPORTANT]
> O projeto usa **`jsonb`** (não `json`) em todas as colunas JSON. O PostgreSQL comprime `jsonb` automaticamente internamente (TOAST). Além disso, todo bulk upsert passa por `json_encode()` explícito na camada de Action — o cast do Model não é acionado em operações de massa. Essa combinação deve ser replicada em todos os campos JSON/JSONB do PCA:
> - Migrations: sempre `$table->jsonb(...)` — nunca `->json(...)`
> - Actions de upsert: sempre `json_encode($dado)` para colunas jsonb antes de passar para `Model::upsert()`
> - Models: cast `'object'` ou `'array'` para acesso hidratado
> - Não usar `JSON_PRETTY_PRINT` nas colunas persistidas (só em logs)

---

## Proposed Changes

### Fase 0 — Órgãos e Unidades (nova infraestrutura de CNPJs)

---

#### [NEW] Migration `create_pncp_orgaos_table`

Tabela de CNPJs monitorados — fonte única de verdade para a sincronização PCA.

```sql
id             bigint PK autoincrement
cnpj           varchar(18)   NOT NULL  UNIQUE
razao_social   varchar(255)  NULL      -- preenchido via importação
poder_id       char(1)       NULL      -- E, L, J, M
esfera_id      char(1)       NULL      -- F, E, M
ativo          boolean       NOT NULL  DEFAULT true  -- permite desativar sem deletar
importado_de   varchar(50)   NULL      -- 'public_contratations' | 'manual'
created_at     timestamp
updated_at     timestamp

INDEX (ativo)
```

#### [NEW] Migration `create_pncp_orgao_unidades_table`

Unidades administrativas descobertas via `/consolidado/unidades`.

```sql
id                  bigint PK autoincrement
cnpj_orgao          varchar(18)   NOT NULL
codigo_unidade      varchar(20)   NOT NULL
nome_unidade        varchar(255)  NULL
uf_sigla            char(2)       NULL
codigo_ibge         integer       NULL
nome_municipio      varchar(100)  NULL
poder_id            char(1)       NULL
esfera_id           char(1)       NULL
ativo               boolean       NOT NULL  DEFAULT true
payload_completo    jsonb         NULL     -- JSON bruto da unidade para futuros usos
created_at          timestamp
updated_at          timestamp

UNIQUE (cnpj_orgao, codigo_unidade)
INDEX  (cnpj_orgao)
INDEX  (uf_sigla)
```

> **Por que `jsonb`?** Mesmo padrão de `public_contratations.payload_completo`: armazena o objeto bruto do PNCP comprimido via TOAST, permitindo acesso a campos sem nova chamada HTTP e indexação futura sem migration de schema.

---

### Fase 1 — Banco de dados PCA

---

#### [NEW] Migration `create_pca_plans_table`

Tabela `pca_plans`: um registro por PCA (combinação única `cnpj_orgao + ano_pca + sequencial_pca`).

```sql
id                  bigint PK autoincrement
cnpj_orgao          varchar(18)   NOT NULL
ano_pca             smallint      NOT NULL
sequencial_pca      integer       NOT NULL
codigo_unidade      varchar(20)   NULL
nome_unidade        varchar(255)  NULL
situacao_pca        varchar(50)   NULL
data_publicacao_pncp  date        NULL
data_inclusao         timestamp   NULL
data_atualizacao      timestamp   NULL
payload_completo    jsonb         NULL     -- JSON bruto do /consolidado
created_at          timestamp
updated_at          timestamp

UNIQUE (cnpj_orgao, ano_pca, sequencial_pca)
INDEX  (cnpj_orgao, ano_pca)
INDEX  (ano_pca)
```

#### [NEW] Migration `create_pca_items_table`

Tabela `pca_items`: itens individuais do PCA.

```sql
id                          bigint PK autoincrement
pca_plan_id                 bigint FK → pca_plans.id
numero_item                 integer       NOT NULL

categoria_item_pca          smallint      NULL
categoria_item_pca_nome     varchar(100)  NULL

catalogo_id                 smallint      NULL
classificacao_catalogo_id   smallint      NULL

classificacao_superior_codigo  varchar(20) NULL
classificacao_superior_nome    varchar(255) NULL

pdm_codigo                  varchar(20)   NULL
pdm_nome                    varchar(255)  NULL

codigo_item                 varchar(20)   NULL
descricao_item              text          NULL

unidade_fornecimento        varchar(20)   NULL

quantidade                  numeric(15,4) NULL
valor_unitario              numeric(15,2) NULL
valor_total                 numeric(17,2) NULL
valor_orcamento_exercicio   numeric(17,2) NULL

data_desejada               date          NULL

unidade_requisitante        varchar(255)  NULL
codigo_unidade_requisitante varchar(20)   NULL

grupo_contratacao_codigo    varchar(20)   NULL
grupo_contratacao_nome      varchar(255)  NULL

data_publicacao_pncp        date          NULL
data_inclusao               timestamp     NULL
data_atualizacao            timestamp     NULL

search_vector               tsvector      GENERATED  -- coluna computada FTS

created_at  timestamp
updated_at  timestamp

UNIQUE (pca_plan_id, numero_item)
INDEX  (pca_plan_id)
INDEX  (pca_plan_id, categoria_item_pca)
INDEX  (pca_plan_id, data_desejada)
INDEX  (pca_plan_id, grupo_contratacao_codigo)
GIN    (search_vector)
```

**FTS — mesma estratégia do projeto:**
```sql
search_vector GENERATED ALWAYS AS (
  to_tsvector('portuguese',
    coalesce(descricao_item, '') || ' ' ||
    coalesce(classificacao_superior_nome, '') || ' ' ||
    coalesce(grupo_contratacao_nome, '') || ' ' ||
    coalesce(unidade_requisitante, '') || ' ' ||
    coalesce(pdm_nome, '')
  )
) STORED
```

#### [NEW] Migration `create_pncp_pca_ciclos_table`

Auditoria durável análoga a `pncp_listagem_ciclos`:

```sql
id            bigint PK
sync_id       uuid UNIQUE NULL
cnpj_orgao    varchar(18)
ano_pca       smallint
sequencial_pca integer NULL     -- NULL = ciclo mestre (antes de descobrir sequenciais)
total_anunciado   integer default 0
total_paginas     integer default 0
items_processados integer default 0
paginas_completas integer default 0
status        varchar(16)        -- started, finished, empty, abandoned
started_at    timestamp NULL
finished_at   timestamp NULL
timestamps
```

---

### Fase 2 — Integração PNCP (PncpClient)

#### [MODIFY] [`PncpClient.php`](file:///c:/anthony/dev/PNCP-Integration/backend/app/Integrations/PNCP/PncpClient.php)

Adicionar métodos PCA sem alterar os existentes:

```php
public function getPcaConsolidado(string $cnpj, int $ano): ?object
public function getPcaItens(string $cnpj, int $ano, int $sequencial, array $params = []): ?object
public function getPcaValorCategoria(string $cnpj, int $ano, int $sequencial): ?object
```

Todos usarão `$this->request()` — mesmo throttle global, mesmo timeout.

#### [MODIFY] [`PncpCircuitBreaker.php`](file:///c:/anthony/dev/PNCP-Integration/backend/app/Integrations/PNCP/PncpCircuitBreaker.php)

Adicionar constante:
```php
public const SCOPE_PCA = 'pca';
```

Nenhuma lógica nova — o breaker já é genérico por escopo.

---

### Fase 3 — DTOs

#### [NEW] `app/DTOs/Pca/PcaSyncParamsDTO.php`
Parâmetros do comando de sincronização (cnpj, ano, etc.).

#### [NEW] `app/DTOs/Pca/PcaPlanData.php`
Dados mapeados do payload do PNCP para um `pca_plans`.

#### [NEW] `app/DTOs/Pca/PcaItemData.php`
Dados mapeados do payload do PNCP para um `pca_items`.

#### [NEW] `app/DTOs/Pca/ListPcaItemsDTO.php`
Filtros de leitura (ano, cnpj, categoriaItem, search, cursor, limit).

---

### Fase 4 — Models

#### [NEW] `app/Models/Pca/PcaPlan.php`
```php
protected $table = 'pca_plans';
protected $casts = ['data_publicacao_pncp' => 'date', 'payload_completo' => 'object', ...];
public function items(): HasMany { ... }
```

#### [NEW] `app/Models/Pca/PcaItem.php`
```php
protected $table = 'pca_items';
protected $casts = ['quantidade' => 'decimal:4', 'valor_total' => 'decimal:2', ...];
public function plan(): BelongsTo { ... }
```

---

### Fase 5 — Actions

#### [NEW] `app/Actions/Pca/SyncPcaPlanAction.php`
Bulk upsert em `pca_plans` a partir de array de `PcaPlanData`.

#### [NEW] `app/Actions/Pca/SyncPcaItemsAction.php`
Bulk upsert em `pca_items` a partir de array de `PcaItemData`. Responsável pelo mapeamento JSON → linha SQL.

---

### Fase 6 — Jobs

#### [NEW] `app/Jobs/Pca/SyncPcaDataJob.php`
- `ShouldQueue, ShouldBeUnique`
- `uniqueId()`: `"{$ano}:{$cnpj}"`
- `retryUntil()`: 12h (`pncp.retry_until_hours.listagem`)
- Usa `PncpFailureHandler::shouldWait()` com `SCOPE_PCA`
- Chama `getPcaConsolidado()` → descobre lista de sequenciais do órgão
- Para cada sequencial → `SyncPcaItemsJob::dispatch()`
- Registra em `pncp_pca_ciclos`

#### [NEW] `app/Jobs/Pca/SyncPcaItemsJob.php`
Sub-mestre por sequencial:
- Chama `getPcaItens(pagina=1)` para descobrir `totalPaginas`
- `PncpSyncCycleTracker::start()` com `syncId` próprio
- Despacha N × `ProcessPcaItemsPageJob`

#### [NEW] `app/Jobs/Pca/ProcessPcaItemsPageJob.php`
Operário por página:
- `PncpFailureHandler::shouldWait()` com `SCOPE_PCA`
- `getPcaItens(pagina)` → `SyncPcaItemsAction::handle()`
- `PncpSyncCycleTracker::markPageDone()`
- Logs: `PNCP_PCA_PAGE_STARTED`, `PNCP_PCA_PAGE_FINISHED`, `PNCP_PCA_PAGE_FAILED`

---

### Fase 7 — Commands

#### [NEW] `app/Console/Commands/ImportPncpOrgaosCommand.php`

```bash
php artisan pncp:import-orgaos
  {--dry-run : Exibe os CNPJs que seriam importados sem persistir}
```

Varre `public_contratations` coletando CNPJs únicos (`DISTINCT orgao_cnpj`) e faz upsert em `pncp_orgaos`. Preserva registros manuais (não sobrescreve `importado_de = 'manual'`). Loga totais: `novos`, `já existentes`, `ignorados`.

#### [NEW] `app/Console/Commands/DiscoverPncpUnidadesCommand.php`

```bash
php artisan pncp:discover-unidades
  {--cnpj=  : Processar apenas este CNPJ}
  {--ano=   : Ano para buscar unidades (padrão: ano atual)}
  {--force  : Reprocessa mesmo os que já têm unidades registradas}
```

Para cada CNPJ ativo em `pncp_orgaos`, chama `getPcaUnidades(cnpj, ano)` via `PncpClient` e faz upsert em `pncp_orgao_unidades`. Respeita o throttle global (passa pelo mesmo `PncpClient::request()`). Loga: `PNCP_UNIDADES_DISCOVERED` por CNPJ.

#### [NEW] `app/Console/Commands/SyncPcaCommand.php`

```bash
php artisan pncp:sync-pca
  {--ano=   : Ano do PCA (padrão: ano atual)}
  {--cnpj=  : CNPJ específico; sem valor, usa todos os ativos de pncp_orgaos}
  {--force  : Ignora verificação de ciclo em andamento}
```

Segue exatamente o padrão do `SyncPncpCommand`: valida opções, lê CNPJs de `pncp_orgaos WHERE ativo = true` (ou o `--cnpj` explícito), monta `PcaSyncParamsDTO` e despacha `SyncPcaDataJob` por CNPJ.

---

### Fase 8 — Fluxo de Leitura

#### [NEW] `app/Http/Requests/Pca/ListPcaItemsRequest.php`
Validação: ano, cnpj, categoriaItem, uf, municipio, search, cursor, limit (1–50).

#### [NEW] `app/Services/Pca/PcaItemsService.php`
Orquestra: `ListPcaItemsDTO → PcaItemRepository → PaginatedResultDTO`.

#### [NEW] `app/Repositories/Pca/PcaItemRepository.php`
- `list(ListPcaItemsDTO): PaginatedResultDTO`
- Cursor keyset baseado em `(data_publicacao_pncp DESC, id ASC)`
- FTS com `search_vector @@ websearch_to_tsquery('portuguese', ?)` quando `search` presente
- Ordenação por relevância (`ts_rank`) quando há busca
- SELECT explícito — só as colunas necessárias

#### [NEW] `app/Http/Controllers/Pca/ListPcaItemsController.php`
```php
public function list(ListPcaItemsRequest $request, PcaItemsService $service): JsonResponse
```

#### [NEW] `app/Resources/Pca/PcaItemResource.php`
Formato de resposta dos itens.

---

### Fase 9 — Rotas

#### [MODIFY] [`api.php`](file:///c:/anthony/dev/PNCP-Integration/backend/routes/api.php)

```php
Route::prefix('pca')->group(function () {
    Route::match(['QUERY', 'GET'], '/items', [ListPcaItemsController::class, 'list'])
        ->middleware('throttle:60,1');
});
```

#### [MODIFY] [`console.php`](file:///c:/anthony/dev/PNCP-Integration/backend/routes/console.php)

```php
// Sincronização diária de PCA (horário diferente para não competir com listagem das 00:45)
Schedule::command('pncp:sync-pca')->dailyAt('03:30');
```

---

### Fase 10 — Testes

#### [NEW] `tests/Feature/Pca/SyncPcaActionTest.php`
- Upsert idempotente (2× sync = mesmo resultado)
- Atualização de dados modificados
- Vínculo correto pca_plan_id → itens

#### [NEW] `tests/Feature/Pca/PcaItemRepositoryTest.php`
- Busca FTS ("notebook" encontra "Notebook Dell")
- Paginação sem duplicação
- Filtros por categoria, ano, cnpj

#### [NEW] `tests/Feature/Jobs/SyncPcaDataJobTest.php`
- 429 → release com circuit breaker
- 500 → retry policy
- Payload vazio → ciclo marcado como empty

---

## Verificação

### Testes automatizados
```bash
php artisan test --filter=Pca
php artisan test  # suite completa para verificar regressão
```

### Manual
- `php artisan pncp:sync-pca --ano=2026 --cnpj={cnpj_real}` em staging
- `EXPLAIN (ANALYZE, BUFFERS)` nas queries de FTS e cursor com volume real
- Verificar `pncp:listagem-gaps` continua funcionando após as migrações

---

## Ordem de Execução (fases do ImplementationPCA.MD)

| Fase doc | Entregas desta implementação |
|----------|------------------------------|
| 1 – Reconhecimento | ✅ Concluído — este documento |
| 2 – Contrato PNCP | Primeiro job real chamará a API e os payloads serão confirmados antes de fixar a migration |
| 3 – Banco | Migrations + índices + FTS |
| 4 – Ingestão | PncpClient + DTOs + Jobs + Actions |
| 5 – Consulta | Request + Service + Repository + Resource + Rotas |
| 6 – Performance | EXPLAIN em queries críticas |
| 7 – Resiliência | Testes de 429/5xx/timeout |
| 8 – Integração | Teste de sincronização simultânea |
| 9 – Refatoração | Somente se houver duplicação clara com risco baixo |
| 10 – Documentação | README atualizado, relatório final |
