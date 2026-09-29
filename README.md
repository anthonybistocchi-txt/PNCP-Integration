# PNCP Integration

API de consulta de contratações públicas e de itens de PCA (Plano de Contratações Anual), com sincronização assíncrona da API do Portal Nacional de Contratações Públicas (PNCP).

Dois serviços Laravel, um Postgres (`pncp`) e um Redis por serviço. A API HTTP fica em `contratacoes`. O sync de órgãos, unidades e PCA fica em `pca`, com fila e workers próprios.

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

#### Contratações

1. O scheduler de `contratacoes` dispara `pncp:sync-contratacoes` todos os dias às **00:45**.
2. O comando enfileira um `SyncContratacoesJob` por modalidade (1–10 por padrão).
3. O mestre consulta o PNCP só para descobrir o total de páginas e faz fan-out de `ProcessContratacaoPageJob` (50 itens por página).
4. Cada operário faz upsert em massa via `SyncContratacaoAction`.
5. Cada ciclo grava uma linha em `ciclos_contratacao` (anunciado no START, processado no FINISH). Gaps incompletos aparecem em `pncp:sync-contratacoes-faltantes`.

**Repescagem semanal de gaps:** sábado e domingo às **02:00**, `pncp:sync-contratacoes-faltantes` reprocessa só ciclos incompletos em `ciclos_contratacao` (dispara `SyncContratacoesJob` por intervalo/modalidade).

Backfill manual de um intervalo específico (ainda disponível via `--days` / datas absolutas):

```bash
docker compose exec php php artisan pncp:sync-contratacoes --data-inicial=20260914 --data-final=20260915 --modalidades=4,6,8,9
docker compose exec php php artisan pncp:sync-contratacoes-faltantes
```

Critério de gap: `abandoned`, `finished` com `itens_processados < total_anunciado`, ou `started` além de `PNCP_RETRY_UNTIL_HOURS_CONTRATACOES` (12h). Não usar contagem de `INSERT` — o upsert atualiza registros já existentes.

#### Contratações — Fase 2 (Documentos)

Ao terminar as contratações de uma modalidade, o sistema dispara `SyncDocumentosJob`, que busca arquivos (`/arquivos`) dos registros que o ciclo tocou (`documentos_sincronizados_em IS NULL` e `updated_at >= início do ciclo`) e distribui lotes em `ProcessDocumentoPageJob`.

#### Contratações — Drenagem de pendentes

`pncp:sync-documentos-pendentes` roda **a cada 20 minutos** e busca registros com `documentos_sincronizados_em` nulo de qualquer data (teto por modalidade e por janela). É o caminho principal dos documentos. Detalhes do dimensionamento em `contratacoes/config/pncp.php`.

---

#### PCA — Órgãos, unidades e sync

Pipeline diário:

1. **18:00** — `pncp:import-orgaos`: allowlist em `orgaos` a partir dos CNPJs distintos de `contratacoes`. Zero HTTP. Preenche `razao_social`, `poder` e `esfera` de `payload_completo->orgaoEntidade` (letras: poder `E|L|J|M`, esfera `F|E|M`). Registros `importado_de = manual` não são sobrescritos; `ativo` também não.
2. **20:00** — `pncp:discover-unidades`: inventário opcional de unidades em `unidades_orgao`. O sync de PCA **não** depende dessa tabela.
3. **12:30** — `pncp:sync-pca`: para cada órgão ativo, despacha `SyncPcaDataJob` → `SyncPcaItemsJob` → `ProcessPcaItemsPageJob`. Persiste em `planos_pca` / `itens_pca` e audita em `ciclos_pca`.

```bash
docker compose exec pca-scheduler php artisan pncp:import-orgaos
docker compose exec pca-scheduler php artisan pncp:import-orgaos --dry-run
docker compose exec pca-scheduler php artisan pncp:sync-pca
docker compose exec pca-scheduler php artisan pncp:sync-pca --ano=2026 --cnpj=00394429000100
docker compose exec pca-scheduler php artisan pncp:discover-unidades
docker compose exec pca-scheduler php artisan pncp:discover-unidades --ano=2026 --force
```

##### De onde vêm os campos de `unidades_orgao`

| Campo | Origem |
|-------|--------|
| `codigo_unidade`, `nome_unidade`, `poder`, `esfera`, `ano_pca` | API `GET .../pca/{ano}/consolidado/unidades` (paginada) |
| `uf_sigla`, `codigo_ibge`, `nome_municipio` | **Enriquecimento local** de `contratacoes.payload_completo->unidadeOrgao` (mesmo CNPJ + `codigoUnidade`). A API de unidades **não** envia geo. Se a unidade nunca apareceu numa contratação, ficam `null`. |

Unique: `(cnpj_orgao, ano_pca, codigo_unidade)`. FK para `orgaos.cnpj`.

---

#### Resiliência

- **Retry por tempo**: jobs usam `retryUntil` — **12h** contratações (`PNCP_RETRY_UNTIL_HOURS_CONTRATACOES`), **12h** PCA (`PNCP_RETRY_UNTIL_HOURS_PCA`), **2h** documentos (`PNCP_RETRY_UNTIL_HOURS_DOCUMENTOS`).
- **Progresso parcial** nos lotes de documentos.
- **Throttle por serviço**: intervalo mínimo entre requisições ao PNCP (`PNCP_MIN_INTERVAL_SECONDS`) no Redis daquele serviço. Os dois podem chamar o PNCP ao mesmo tempo.
- **Circuit breaker** (Redis do próprio serviço): escopos `contratacoes`, `documentos` e `pca`.
- **Logs estruturados**: `PNCP_CONTRATACOES_*`, `PNCP_DOCUMENTOS_*`, `PNCP_PCA_*`, `PNCP_UNIDADES_*`, `PNCP_BREAKER_*` em `storage/logs/laravel.log`.

## Stack

- PHP 8.3+
- Laravel 13
- PostgreSQL
- Redis (um container por serviço: fila, cache, circuit breaker, unique jobs)
- Docker Compose (nginx, php-fpm, postgres, dois Redis, dois schedulers, workers de cada serviço)

## Como rodar

Os arquivos de compose ficam na **raiz do monorepo**.

### 1. Variáveis de ambiente

Copie e preencha:

- `.env` na raiz do projeto (senhas do Postgres/Redis usadas pelo compose)
- `contratacoes/.env` e `pca/.env` (`DB_PASSWORD` e `REDIS_PASSWORD` iguais aos da raiz; `DB_DATABASE=pncp`)

### 2. Subir os containers

```bash
cd ..   # raiz do projeto (ao lado de docker-compose.yml)
docker compose up -d
```

Sobe: nginx (porta 8000), php, postgres (`pncp`), `redis-contratacoes` (6379), `redis-pca` (6380), scheduler e 3 workers de contratações, `pca-scheduler` e 2 workers de PCA.

Produção (limites de CPU/RAM):

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d
```

### 3. Migrar e seedar

```bash
docker compose exec php php artisan migrate --seed
docker compose exec pca-scheduler php artisan migrate --force
```

O primeiro comando cria usuários, contratações e a extensão `pg_stat_statements`. O segundo cria órgãos, unidades, planos e itens de PCA no mesmo banco.

### 4. Sync manual (opcional)

```bash
docker compose exec php php artisan pncp:sync-contratacoes
docker compose exec php php artisan pncp:sync-contratacoes --days=30 --modalidades=6,8
docker compose exec php php artisan pncp:sync-documentos-pendentes
docker compose exec pca-scheduler php artisan pncp:import-orgaos
docker compose exec pca-scheduler php artisan pncp:sync-pca
```

Não é necessário subir `queue:work` à mão: os workers já sobem com o compose.

### Agendamento

| Horário | Comando | Função | Serviço |
| diário 00:45 | `pncp:sync-contratacoes` | Contratações do dia + disparo dos documentos do ciclo | `contratacoes` |
| `*/20` | `pncp:sync-documentos-pendentes` | Drenagem contínua de documentos | `contratacoes` |
| sáb/dom 02:00 | `pncp:sync-contratacoes-faltantes` | Reprocessa ciclos incompletos | `contratacoes` |
| diário 18:00 | `pncp:import-orgaos` | Atualiza allowlist de órgãos | `pca` |
| diário 20:00 | `pncp:discover-unidades` | Inventário de unidades | `pca` |
| diário 12:30 | `pncp:sync-pca` | Sincroniza PCA dos órgãos ativos | `pca` |

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

#### Contratações — `/api/v1/contratacoes`

##### `QUERY /api/v1/contratacoes`

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

##### `GET /api/v1/contratacoes/relevant/include`

Contratações relevantes ao usuário (modo include), com `cursor` e `limit` opcionais.

##### `GET /api/v1/contratacoes/relevant/both`

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

Nas tabelas `orgaos` e `unidades_orgao` a coluna se chama **`poder`**. Na API de filtros de contratações o parâmetro continua `poderId` (legado do payload PNCP).

### `esfera` / `esferaId` (letra, não ID numérico)

- `F`: Federal
- `E`: Estadual
- `M`: Municipal
- `D`: Distrital (DF)
- `N`: Não se aplica

Nas tabelas `orgaos` e `unidades_orgao` a coluna se chama **`esfera`**.

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
