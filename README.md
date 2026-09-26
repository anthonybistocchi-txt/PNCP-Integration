# PNCP Integration - Backend

Backend da aplicação **PNCP Integration**: API de consulta de licitações/contratos públicos e de itens de PCA (Plano de Contratações Anual), com sincronização assíncrona da API do Portal Nacional de Contratações Públicas (PNCP).

## Arquitetura

Dois fluxos isolados:

### 1. Fluxo de Leitura (síncrono / frontend)

Focado em latência baixa.

- O frontend lê apenas o PostgreSQL local (fonte de verdade).
- Rotas autenticadas usam o middleware `auth.api` (header `API_KEY` ou Bearer token do usuário).
- Validação via FormRequest → DTO → Service → Repository.
- A leitura **nunca** chama a API do governo.

### 2. Fluxo de Sincronização (assíncrono / background)

Focado em ingestão em volume. Há dois domínios: **contratações** e **PCA**.

---

#### Contratações — Fase 1 (Listagem)

1. O scheduler dispara `pncp:sync` todos os dias às **00:45**.
2. O comando enfileira um `SyncPncpDataJob` por modalidade (1–10 por padrão).
3. O mestre consulta o PNCP só para descobrir o total de páginas e faz fan-out de `ProcessPncpPageJob` (50 itens por página).
4. Cada operário faz upsert em massa via `SyncPublicContratationAction`.
5. Cada ciclo grava uma linha em `pncp_listagem_ciclos` (anunciado no START, processado no FINISH). Gaps incompletos aparecem em `pncp:sync-listagem-faltantes`.

**Repescagem semanal de gaps:** sábado e domingo às **02:00**, `pncp:sync-listagem-faltantes` reprocessa só ciclos incompletos em `pncp_listagem_ciclos` (dispara `SyncPncpDataJob` por intervalo/modalidade). Substitui o antigo backfill cego `pncp:sync --days=7`.

Backfill manual de um intervalo específico (ainda disponível via `--days` / datas absolutas):

```bash
php artisan pncp:sync --data-inicial=20260914 --data-final=20260915 --modalidades=4,6,8,9
php artisan pncp:sync-listagem-faltantes
```

Critério de gap: `abandoned`, `finished` com `items_processados < total_anunciado`, ou `started` além de `PNCP_RETRY_UNTIL_HOURS_LISTAGEM` (12h). Não usar contagem de `INSERT` — o upsert atualiza registros já existentes. `pncp:sync-listagem-faltantes` roda sábado e domingo às 02:00.

#### Contratações — Fase 2 (Documentos)

Ao terminar a listagem de uma modalidade, o sistema dispara `SyncPncpDocumentsJob`, que busca arquivos (`/arquivos`) dos registros que o ciclo tocou (`documents_fetched_at IS NULL` e `updated_at >= início do ciclo`) e distribui lotes em `ProcessPncpDocumentPageJob`.

#### Contratações — Drenagem de pendentes

`pncp:sync-documentos-pendentes` roda **a cada 20 minutos** e busca registros com `documents_fetched_at` nulo de qualquer data (teto por modalidade e por janela). É o caminho principal dos documentos. Detalhes do dimensionamento em `config/pncp.php`.

---

#### PCA — Órgãos, unidades e sync

Pipeline diário:

1. **18:00** — `pncp:import-orgaos`: allowlist em `pncp_orgaos` a partir dos CNPJs distintos de `public_contratations`. Zero HTTP. Preenche `razao_social`, `poder` e `esfera` de `payload_completo->orgaoEntidade` (letras: poder `E|L|J|M`, esfera `F|E|M`). Registros `importado_de = manual` não são sobrescritos; `ativo` também não.
2. **20:00** — `pncp:discover-unidades`: inventário opcional de unidades em `pncp_orgao_unidades`. O sync de PCA **não** depende dessa tabela.
3. **12:30** — `pncp:sync-pca`: para cada órgão ativo, despacha `SyncPcaDataJob` → `SyncPcaItemsJob` → `ProcessPcaItemsPageJob`. Persiste em `pca_plans` / `pca_items` e audita em `pncp_pca_ciclos`.

```bash
# Allowlist (também roda sozinha no schedule)
php artisan pncp:import-orgaos
php artisan pncp:import-orgaos --dry-run

# Sync PCA
php artisan pncp:sync-pca
php artisan pncp:sync-pca --ano=2026 --cnpj=00394429000100

# Unidades (opcional, sob demanda ou agendado às 20:00)
php artisan pncp:discover-unidades
php artisan pncp:discover-unidades --ano=2026 --force
```

##### De onde vêm os campos de `pncp_orgao_unidades`

| Campo | Origem |
|-------|--------|
| `codigo_unidade`, `nome_unidade`, `poder`, `esfera`, `ano_pca` | API `GET .../pca/{ano}/consolidado/unidades` (paginada) |
| `uf_sigla`, `codigo_ibge`, `nome_municipio` | **Enriquecimento local** de `public_contratations.payload_completo->unidadeOrgao` (mesmo CNPJ + `codigoUnidade`). A API de unidades **não** envia geo. Se a unidade nunca apareceu numa contratação, ficam `null`. |

Unique: `(cnpj_orgao, ano_pca, codigo_unidade)`. FK para `pncp_orgaos.cnpj`.

---

#### Resiliência

- **Retry por tempo**: jobs usam `retryUntil` — **12h** listagem (`PNCP_RETRY_UNTIL_HOURS_LISTAGEM`), **2h** documentos (`PNCP_RETRY_UNTIL_HOURS_DOCUMENTOS`).
- **Progresso parcial** nos lotes de documentos.
- **Throttle global**: intervalo mínimo entre requisições ao PNCP (`PNCP_MIN_INTERVAL_SECONDS`), compartilhado por todos os workers.
- **Circuit breaker** (Redis): escopos separados — `listagem`, `documentos` e `pca`.
- **Logs estruturados**: `PNCP_LISTAGEM_*`, `PNCP_DOCUMENTOS_*`, `PNCP_PCA_*`, `PNCP_UNIDADES_*`, `PNCP_BREAKER_*` em `storage/logs/laravel.log`.

## Stack

- PHP 8.3+
- Laravel 13
- PostgreSQL
- Redis (fila, cache, circuit breaker, unique jobs)
- Docker Compose (nginx, php-fpm, postgres, redis, scheduler, 3 workers)

## Como rodar

Os arquivos de compose ficam na **raiz do monorepo** (`../` a partir de `backend/`), não dentro de `backend/`.

### 1. Variáveis de ambiente

Copie e preencha:

- `.env` na raiz do projeto (senhas do Postgres/Redis usadas pelo compose)
- `backend/.env` (app Laravel; `DB_PASSWORD` e `REDIS_PASSWORD` devem bater com a raiz)

### 2. Subir os containers

```bash
cd ..   # raiz do projeto (ao lado de docker-compose.yml)
docker compose up -d
```

Sobe: nginx (porta 8000), php, postgres, redis, scheduler e **3 workers** (`worker`, `worker-2`, `worker-3`).

Produção (limites de CPU/RAM):

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d
```

### 3. Migrar e seedar

```bash
docker compose exec php php artisan migrate --seed
```

Cria o usuário admin (API key no banco; ver seeder).

### 4. Sync manual (opcional)

```bash
# Listagem (ontem→hoje, modalidades 1–10)
docker compose exec php php artisan pncp:sync

# Só algumas modalidades / janela maior
docker compose exec php php artisan pncp:sync --days=30 --modalidades=6,8

# Documentos pendentes
docker compose exec php php artisan pncp:sync-documentos-pendentes

# PCA
docker compose exec php php artisan pncp:import-orgaos
docker compose exec php php artisan pncp:sync-pca
```

Não é necessário subir `queue:work` à mão: os workers já sobem com o compose.

### Agendamento

| Horário | Comando | Função |
|--------|---------|--------|
| diário 00:45 | `pncp:sync` | Listagem diária + disparo automático da Fase 2 |
| `*/20` | `pncp:sync-documentos-pendentes` | Drenagem contínua de documentos |
| sáb/dom 02:00 | `pncp:sync-listagem-faltantes` | Reprocessa ciclos incompletos de listagem |
| diário 18:00 | `pncp:import-orgaos` | Atualiza allowlist de órgãos (PCA) |
| diário 20:00 | `pncp:discover-unidades` | Inventário de unidades (enriquecimento opcional) |
| diário 12:30 | `pncp:sync-pca` | Sincroniza PCA dos órgãos ativos |

`pncp:discover-unidades` está **agendado** para 20:00 (enriquecimento opcional; o sync de PCA não depende dessa tabela).

## Endpoints da API

Base: `http://localhost:8000/api`

### Público

#### `GET /api/healthz`

Health check (sem autenticação). Retorna status de banco e Redis.

### Autenticados

Header: `API_KEY: <chave do usuário>` (ou `Authorization: Bearer <chave>`) e `Accept: application/json`.

#### `GET /api/me`

Dados do usuário autenticado.

#### `POST /api/auth/register`

Criação de usuário (exige permissão no middleware `verify_permission_to_create_user`).

#### Keywords — `/api/v1/keywords`

| Método | Rota | Descrição |
|--------|------|-----------|
| GET | `/` | Lista keywords do usuário |
| POST | `/` | Cria/atualiza |
| PUT | `/{id}` | Atualiza |
| DELETE | `/{id}` | Remove |

#### Filters — `/api/v1/filters`

| Método | Rota | Descrição |
|--------|------|-----------|
| GET | `/` | Lista filtros do usuário |
| POST | `/` | Cria/atualiza |
| DELETE | `/{id}` | Remove |

#### Contracts — `/api/v1/contracts`

##### `QUERY /api/v1/contracts`

Busca com filtros no body (método HTTP `QUERY`).

**Body (JSON):**

```json
{
  "dataInicial": "20260101",
  "dataFinal": "20260815",
  "codigoModalidadeContratacao": 6,
  "tipoData": "publicacao",
  "uf": "SP",
  "codigoMunicipioIbge": 3543402,
  "limit": 15,
  "cursor": null,
  "poderId": "E",
  "esferaId": "M",
  "situacaoCompraId": 1,
  "modoDisputaId": 1,
  "tipoInstrumentoConvocatorioCodigo": 1,
  "fontesOrcamentarias": 2
}
```

- Obrigatórios: `dataInicial`, `dataFinal`, `codigoModalidadeContratacao`
- `limit` máximo: 50
- Paginação por cursor (keyset)

**Resposta (exemplo):**

```json
{
  "data": [
    {
      "numeroControle": "12345/2026",
      "processo": "0001/2026"
    }
  ],
  "count_page": 15,
  "next_cursor": "eyJkYXRlIjoiMjAyNi0wOC0xNSIsIm51bWVyb19jb250cm9sZSI6Ii4uLiJ9",
  "has_more": true
}
```

Envie `next_cursor` em `cursor` na próxima página. Fim quando `has_more` for `false` ou `next_cursor` for `null`.

**`tipoData`:**

- `publicacao` (padrão): filtra pela data de publicação do edital
- `atualizacao`: filtra por licitações que sofreram alteração no intervalo

##### `GET /api/v1/contracts/relevant/include`

Contratações relevantes ao usuário (modo include), com `cursor` e `limit` opcionais.

##### `GET /api/v1/contracts/relevant/both`

Idem, modo both (include + exclude).

#### PCA — `/api/v1/pca`

##### `QUERY` / `GET /api/v1/pca/items`

Lista itens de PCA do banco local (não chama o PNCP).

**Filtros (query/body):** `ano`, `cnpj`, `categoriaItem`, `uf`, `municipio`, `search`, `cursor`, `limit` (máx. 50).

Paginação por cursor. Resposta: `{ "data": [...], "next_cursor": "..." }`.

## Tabelas de domínio (PNCP)

### `poder` / `poderId` (letra, não ID numérico)

- `E`: Executivo
- `L`: Legislativo
- `J`: Judiciário
- `N`: Não se aplica
- `M`: Ministério Público (quando aplicável na fonte)

Nas tabelas `pncp_orgaos` e `pncp_orgao_unidades` a coluna se chama **`poder`**. Na API de filtros de contratos o parâmetro continua `poderId` (legado do payload PNCP).

### `esfera` / `esferaId` (letra, não ID numérico)

- `F`: Federal
- `E`: Estadual
- `M`: Municipal
- `D`: Distrital (DF)
- `N`: Não se aplica

Nas tabelas `pncp_orgaos` e `pncp_orgao_unidades` a coluna se chama **`esfera`**.

### `situacaoCompraId`

- `1`: Divulgada no PNCP
- `2`: Revogada
- `3`: Anulada
- `4`: Suspensa

### `modalidadeId` / `codigoModalidadeContratacao`

- `1`: Leilão
- `2`: Diálogo Competitivo
- `3`: Concurso
- `4`: Concorrência
- `5`: Concurso (específico) / Chamamento Público
- `6`: Pregão
- `7`: Dispensa de Licitação
- `8`: Inexigibilidade
- `9`: Credenciamento
- `10`: Pré-qualificação

### `modoDisputaId`

- `1`: Aberto
- `2`: Fechado
- `3`: Aberto e Fechado
- `4`: Fechado e Aberto
- `5`: Não se aplica

### `tipoInstrumentoConvocatorioCodigo`

- `1`: Edital
- `2`: Aviso de Contratação Direta
- `3`: Ato de Inexigibilidade
- `4`: Ato de Dispensa

### `fontesOrcamentarias`

- `1`: Recursos do Tesouro
- `2`: Outras Fontes
