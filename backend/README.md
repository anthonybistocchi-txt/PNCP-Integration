# PNCP Integration - Backend

Backend da aplicação **PNCP Integration**: API de consulta de licitações e contratos públicos, com sincronização assíncrona da API do Portal Nacional de Contratações Públicas (PNCP).

## Arquitetura

Dois fluxos isolados:

### 1. Fluxo de Leitura (síncrono / frontend)

Focado em latência baixa.

- O frontend lê apenas o PostgreSQL local (fonte de verdade).
- Rotas autenticadas usam o middleware `auth.api` (header `API_KEY` ou Bearer token do usuário).
- Validação via FormRequest (`ListPublicContratationsRequest`), conversão para DTO, Service e Repository.
- A leitura **nunca** chama a API do governo.

### 2. Fluxo de Sincronização (assíncrono / background)

Focado em ingestão em volume.

#### Fase 1 — Listagem

1. O scheduler dispara `pncp:sync` diariamente às **04:30**.
2. O comando enfileira um `SyncPncpDataJob` por modalidade (1–10 por padrão).
3. O mestre consulta o PNCP só para descobrir o total de páginas e faz fan-out de `ProcessPncpPageJob` (50 itens por página).
4. Cada operário faz upsert em massa via `SyncPublicContratationAction`.

#### Fase 2 — Documentos (automática)

Ao terminar a listagem de uma modalidade, o sistema dispara `SyncPncpDocumentsJob`, que busca arquivos (`/arquivos`) dos registros que o ciclo tocou (`documents_fetched_at IS NULL` e `updated_at >= início do ciclo`) e distribui lotes em `ProcessPncpDocumentPageJob`.

#### Varredura de pendentes (rede de segurança)

`pncp:sync-documentos-pendentes` às **09:00** e **21:00** reprocessa registros com `documents_fetched_at` nulo de qualquer data (teto por modalidade, mais recentes primeiro). Cobre o que a Fase 2 não conseguiu quando o PNCP estava instável.

#### Resiliência

- **Retry por tempo**: jobs usam `retryUntil` (~6h via `PNCP_RETRY_UNTIL_HOURS`), não um contador fixo de 5 tentativas.
- **Throttle global**: intervalo mínimo entre requisições ao PNCP (`PNCP_MIN_INTERVAL_SECONDS`), compartilhado por todos os workers.
- **Circuit breaker** (Redis): pausa escalonada em 429 e em falhas consecutivas 5xx/timeout; escopos separados para listagem e documentos.
- **Logs estruturados**: eventos nomeados (`PNCP_LISTAGEM_*`, `PNCP_DOCUMENTOS_*`, `PNCP_BREAKER_*`, etc.) em `storage/logs/laravel.log`. Progresso da fila também aparece no stdout dos containers (`docker compose logs worker`).

## Stack

- PHP 8.3+
- Laravel 13
- PostgreSQL
- Redis (fila, cache, circuit breaker, unique jobs)
- Docker Compose (nginx, php-fpm, postgres, redis, scheduler, 3 workers)

## Como rodar

Os arquivos de compose ficam na **raiz do monorepo** (`../` a partir desta pasta), não dentro de `backend/`.

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

# Varredura de documentos pendentes
docker compose exec php php artisan pncp:sync-documentos-pendentes
```

Não é necessário subir `queue:work` à mão: os workers já sobem com o compose.

### Agendamento

| Horário | Comando | Função |
|--------|---------|--------|
| 04:30 | `pncp:sync` | Listagem diária + disparo automático da Fase 2 |
| 09:00 | `pncp:sync-documentos-pendentes` | Drena passivo de documentos |
| 21:00 | `pncp:sync-documentos-pendentes` | Segunda chance no dia |

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

## Tabelas de domínio (PNCP)

### `poderId`

- `E`: Executivo
- `L`: Legislativo
- `J`: Judiciário
- `N`: Não se aplica

### `esferaId`

- `F`: Federal
- `E`: Estadual
- `M`: Municipal
- `D`: Distrital (DF)
- `N`: Não se aplica

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
