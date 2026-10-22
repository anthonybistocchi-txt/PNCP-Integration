# PNCP Integration

API de consulta de contratações públicas, de itens de PCA (Plano de Contratações Anual) e de órgãos. O frontend lê o Postgres local. A API do PNCP só é chamada pelos workers, em segundo plano.

Dois serviços Laravel, um banco `pncp` e um Redis por serviço.

| | `contratacoes` | `pca` |
|---|---|---|
| O que faz | Licitações, documentos e a API HTTP (contratações, itens de PCA e órgãos) | Órgãos, unidades, planos e itens de PCA |
| Fila | `redis-contratacoes` · porta 6379 | `redis-pca` · porta 6380 |
| Quem processa | scheduler + 3 workers | `pca-scheduler` + 4 workers |
| Entra na API | sim, nginx na porta 8000 | não; só grava no mesmo Postgres |

## Neste arquivo

- [Leitura](#leitura)
- [Sincronização](#sincronização)
- [O que volta sozinho](#o-que-volta-sozinho)
- [Agenda](#agenda)
- [Como rodar](#como-rodar)
- [API](#api)
- [Códigos do PNCP](#códigos-do-pncp)

## Leitura

O frontend lê só o PostgreSQL. A request não chama o governo.

`Accept: application/json` e, nas rotas fechadas, `API_KEY` ou `Authorization: Bearer`. O caminho interno é FormRequest, DTO, Service, Repository.

## Sincronização

### Contratações

Todo dia às **00:45**, `pncp:sync-contratacoes` cobre de ontem até hoje (`--days=1`).

1. Um `SyncContratacoesJob` por modalidade (1 a 10). Nesse disparo já nasce a linha em `ciclos_contratacao`, com status `started` e total ainda 0.
2. O mestre descobre o total de páginas e grava o anunciado nessa mesma linha. Se o PNCP vier vazio, ela fecha como `empty`.
3. `ProcessContratacaoPageJob` faz upsert de 50 registros por página (`SyncContratacaoAction`).
4. No fim, a linha recebe o processado. Se o mestre morrer antes do total, ela continua incompleta e o reconciliador pega o intervalo.

Quando a modalidade termina, `SyncDocumentosJob` busca `/arquivos` dos registros daquele ciclo (`documentos_sincronizados_em` nulo e `updated_at` a partir do início) e distribui `ProcessDocumentoPageJob`.

A drenagem `pncp:sync-documentos-pendentes` roda **a cada 20 minutos** e é o caminho principal dos documentos: qualquer licitação com a marca nula, de qualquer data, com teto por modalidade e por janela. O dimensionamento está em `contratacoes/config/pncp.php`.

Sábado e domingo às **02:00**, `pncp:sync-contratacoes-faltantes` reabre os ciclos incompletos, os dias que ficaram sem linha e as janelas dos últimos 7 dias (`PNCP_CONTRATACOES_RECHECK_DAYS`), mesmo as que fecharam. A madrugada também busca o dia que ficou sem ciclo, a partir da primeira execução desta cobertura.

> [!NOTE]
> Gap de contratação: `abandoned`, `finished` com `itens_processados` menor que `total_anunciado`, `started` além de 12h, ou ciclo que ficou sem total anunciado. Dia sem nenhuma linha volta na próxima madrugada, desde a primeira execução da cobertura. Uma atualização do PNCP posterior à busca de documentos zera `documentos_sincronizados_em` e a drenagem pega de novo. Contar `INSERT` não serve: o upsert atualiza linha que já existe.

```bash
docker compose exec php php artisan pncp:sync-contratacoes --data-inicial=20260914 --data-final=20260915 --modalidades=4,6,8,9
docker compose exec php php artisan pncp:sync-contratacoes-faltantes
```

### PCA

| Quando | Comando | O que faz |
|---|---|---|
| todo dia 12:00 | `pncp:import-orgaos` | Allowlist em `orgaos` a partir dos CNPJs de `contratacoes`. Zero HTTP. Preenche `razao_social`, `poder` e `esfera`. Manual (`importado_de = manual`) e `ativo` não são sobrescritos. |
| domingo 12:10 | `pncp:discover-unidades` | Inventário opcional em `unidades_orgao`, só de órgão que já tem plano. O sync de itens não usa essa tabela. |
| segunda e quinta 00:00 | `pncp:sync-pca` | Lista planos no PNCP e baixa itens pendentes. |
| todo dia 06:00 | `pncp:sync-pca-faltantes` | Rebaixa itens de planos **já gravados**. Não relista órgão. Teto de 2000 planos por execução. |

Na listagem, `SyncPcaDataJob` chama `consolidado/unidades`, grava `planos_pca` e fecha o ciclo do órgão. Cada plano pendente segue `SyncPcaItemsJob` → `ProcessPcaItemsPageJob` (500 itens por página) até `itens_pca`. A auditoria fica em `ciclos_pca`.

> [!IMPORTANT]
> Há dois prazos de 30 dias, e eles não se misturam.
>
> | | Marca dos itens | Órgão sem PCA |
> |---|---|---|
> | Onde | `planos_pca.itens_sincronizados_em` | `orgaos.pca_tem_plano = false` e `pca_verificado_em` |
> | Quando nasce | o ciclo do plano fecha completo | a listagem rodou e o PNCP devolveu vazio |
> | O que acontece ao vencer | a respescagem das 06:00 baixa os itens de novo | segunda e quinta voltam a consultar esse CNPJ |
> | Variável | `PNCP_PCA_REFRESH_AFTER_DAYS` (30) | `PNCP_PCA_RECHECK_EMPTY_DAYS` (30) |
>
> Plano pendente, para a respescagem: nunca baixado por completo, atualizado no PNCP depois da marca, ou com a marca mais velha que 30 dias. Um órgão marcado sem PCA continua com os itens já gravados sujeitos à marca do plano. Plano novo nesse CNPJ espera os 30 dias da listagem.

```bash
docker compose exec pca-scheduler php artisan pncp:import-orgaos
docker compose exec pca-scheduler php artisan pncp:import-orgaos --dry-run
docker compose exec pca-scheduler php artisan pncp:sync-pca --ano=2026 --cnpj=00394429000100
docker compose exec pca-scheduler php artisan pncp:sync-pca-faltantes --dry-run
docker compose exec pca-scheduler php artisan pncp:discover-unidades --ano=2026 --force
```

Campos de `unidades_orgao`. Unique `(cnpj_orgao, ano_pca, codigo_unidade)`, com FK para `orgaos.cnpj`.

| Campo | Origem |
|---|---|
| `codigo_unidade`, `nome_unidade`, `poder`, `esfera`, `ano_pca` | `GET .../pca/{ano}/consolidado/unidades` |
| `uf_sigla`, `codigo_ibge`, `nome_municipio` | Enriquecimento local de `contratacoes.payload_completo->unidadeOrgao` (mesmo CNPJ + `codigoUnidade`). A API de unidades não envia geo; se a unidade nunca apareceu numa contratação, ficam nulos. |

### Resiliência

| Escopo | `retryUntil` | Variável |
|---|---|---|
| Contratações | 12h | `PNCP_RETRY_UNTIL_HOURS_CONTRATACOES` |
| PCA | 72h | `PNCP_RETRY_UNTIL_HOURS_PCA` em `pca/config/pncp.php` |
| Documentos | 2h | `PNCP_RETRY_UNTIL_HOURS_DOCUMENTOS` |

Cada serviço tem o próprio Redis para fila, cache, unique jobs, intervalo mínimo (`PNCP_MIN_INTERVAL_SECONDS`) e circuit breaker (`contratacoes`, `documentos`, `pca`). Os dois podem chamar o PNCP ao mesmo tempo. Lotes de documentos guardam progresso parcial. Logs: `PNCP_CONTRATACOES_*`, `PNCP_DOCUMENTOS_*`, `PNCP_PCA_*`, `PNCP_UNIDADES_*`, `PNCP_BREAKER_*`.

## O que volta sozinho

| O que falhou | Quem recupera | Quando |
|---|---|---|
| Itens de um plano que já está em `planos_pca` | `pncp:sync-pca-faltantes` | no dia seguinte, 06:00, até 2000 planos. A rede de 30 dias cobre plano cuja data no PNCP não mudou. |
| Documento com marca nula | `pncp:sync-documentos-pendentes` | a cada 20 minutos, sem filtro de data |
| Licitação de ontem ou de hoje | `pncp:sync-contratacoes` | na madrugada seguinte, 00:45 |
| Dia de contratação que ficou sem linha de ciclo | `pncp:sync-contratacoes` e `pncp:sync-contratacoes-faltantes` | na próxima execução, desde a primeira cobertura. Antes dela, só os últimos `PNCP_CONTRATACOES_RECHECK_DAYS` (7). |
| Janela de contratação já fechada, para pegar total curto ou alteração | `pncp:sync-contratacoes-faltantes` | sábado e domingo, 02:00, últimos 7 dias |
| Ciclo de contratação que saiu dessa janela e ficou em gap | `pncp:sync-contratacoes-faltantes` | sábado e domingo, 02:00. Se um ciclo posterior fechou o mesmo intervalo, não rebusca. |
| Listagem de órgão que morreu antes de terminar | `pncp:sync-pca-faltantes` | no dia seguinte, 06:00. A listagem completa continua segunda e quinta. |
| Órgão marcado sem PCA | `pncp:sync-pca` | 30 dias depois de `pca_verificado_em` |

Um job que foi para `failed_jobs` não volta para a fila. A recuperação é o agendamento da tabela acima.

## Agenda

| Horário | Comando | Serviço |
|---|---|---|
| diário 00:45 | `pncp:sync-contratacoes` | `contratacoes` |
| a cada 20 min | `pncp:sync-documentos-pendentes` | `contratacoes` |
| sáb/dom 02:00 | `pncp:sync-contratacoes-faltantes` | `contratacoes` |
| diário 12:00 | `pncp:import-orgaos` | `pca` |
| domingo 12:10 | `pncp:discover-unidades` | `pca` |
| seg/qui 00:00 | `pncp:sync-pca` | `pca` |
| diário 06:00 | `pncp:sync-pca-faltantes` | `pca` |

## Como rodar

PHP 8.3+, Laravel 13, PostgreSQL, Redis e Docker Compose. Os arquivos de compose ficam na raiz do repositório.

**1. Ambiente.** Copie e preencha `.env` na raiz (senhas do Postgres e do Redis usadas pelo compose) e `contratacoes/.env` e `pca/.env`. `DB_PASSWORD` e `REDIS_PASSWORD` iguais aos da raiz. `DB_DATABASE=pncp`.

**2. Containers.** Na raiz, ao lado de `docker-compose.yml`:

```bash
docker compose up -d
```

Sobe nginx (`8000`), php, postgres (`pncp`), os dois Redis, os dois schedulers, 3 workers de contratações e 4 de PCA. Os workers já sobem com o compose. Postgres (`5432`) e os dois Redis (`6379` e `6380`) escutam só em `127.0.0.1`.

Produção, com limite de CPU e RAM:

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d
```

**3. Migrações.**

```bash
docker compose exec php php artisan migrate --seed
docker compose exec pca-scheduler php artisan migrate --force
```

O primeiro cria usuários, contratações e a extensão `pg_stat_statements`. O segundo cria órgãos, unidades, planos e itens no mesmo banco.

**4. Sync manual**, se quiser disparar fora da agenda:

```bash
docker compose exec php php artisan pncp:sync-contratacoes
docker compose exec php php artisan pncp:sync-contratacoes --days=30 --modalidades=6,8
docker compose exec php php artisan pncp:sync-documentos-pendentes
docker compose exec pca-scheduler php artisan pncp:import-orgaos
docker compose exec pca-scheduler php artisan pncp:sync-pca
docker compose exec pca-scheduler php artisan pncp:sync-pca-faltantes
```

## API

Base: `http://localhost:8000/api`

Rotas fechadas: `API_KEY: <chave>` ou `Authorization: Bearer <chave>`, e `Accept: application/json`.

| Método | Rota | O que faz |
|---|---|---|
| `GET` | `/api/healthz` | Banco e Redis. Sem autenticação. |
| `GET` | `/api/me` | Usuário autenticado. |
| `POST` | `/api/auth/register` | Cria usuário. Exige `verify_permission_to_create_user`. |
| `GET` `POST` | `/api/v1/keywords` | Lista ou cria/atualiza keywords do usuário. |
| `PUT` `DELETE` | `/api/v1/keywords/{id}` | Atualiza ou remove. |
| `GET` `POST` | `/api/v1/filters` | Lista ou cria/atualiza filtros do usuário. |
| `DELETE` | `/api/v1/filters/{id}` | Remove. |
| `QUERY` | `/api/v1/contratacoes` | Busca licitações no banco local. Filtros no body. |
| `GET` | `/api/v1/contratacoes/relevant/include` | Relevantes do usuário, modo include. `cursor` e `limit` opcionais. |
| `GET` | `/api/v1/contratacoes/relevant/both` | Modo both (include e exclude). |
| `QUERY` | `/api/v1/pca/items` | Itens de PCA no banco local. Não chama o PNCP. |
| `QUERY` | `/api/v1/orgaos` | Órgãos ativos. `cursor` e `limit` opcionais. |
| `QUERY` | `/api/v1/orgaos/{cnpj}` | Um órgão, com planos e itens de PCA. 404 se o CNPJ não existir. |

### `QUERY /api/v1/contratacoes`

Obrigatórios: `dataInicial`, `dataFinal`, `codigoModalidadeContratacao`. `limit` máximo 50. Paginação por cursor: mande `next_cursor` em `cursor` até `has_more` ser `false`.

`tipoData`: `publicacao` (padrão) filtra a data do edital; `atualizacao` filtra licitação alterada no intervalo.

<details>
<summary>Exemplo de body e resposta</summary>

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

```json
{
  "data": [
    {
      "numeroControle": "12345/2026",
      "processo": "0001/2026",
      "objetoCompra": "Aquisição de notebooks",
      "valorTotalEstimado": 150000,
      "documentos": []
    }
  ],
  "page": 1,
  "count_page": 15,
  "count": 15,
  "next_cursor": "eyJkYXRlIjoiMjAyNi0wOC0xNSIsIm51bWVyb19jb250cm9sZSI6Ii4uLiJ9",
  "has_more": true
}
```

Cada item também traz `valorTotalHomologado`, `detalhesDisputa`, `orgaoEntidade`, `unidade`, `datas` e `links`.

</details>

### `QUERY /api/v1/pca/items`

Filtros no body: `ano`, `cnpj`, `categoriaItem`, `search`, `cursor`, `limit` (máx. 50).

O envelope é o mesmo das contratações: `data`, `page`, `count_page`, `count`, `next_cursor`, `has_more`.

### `QUERY /api/v1/orgaos`

Lista só órgãos com `ativo = true`, em ordem de CNPJ. Body opcional: `cursor`, `limit` (máx. 50, padrão 15).

Cada item: `cnpj`, `razao_social`, `poder`, `esfera`, `ativo`. O envelope é o mesmo das contratações.

### `QUERY /api/v1/orgaos/{cnpj}`

`cnpj` com 14 dígitos. Resposta: `{ "data": { ...órgão, "planos": [ { ...plano, "itens": [...] } ] } }`. Planos do mais novo para o mais antigo; itens por `numero_item`. CNPJ inexistente devolve 404.

## Códigos do PNCP

Nas tabelas `orgaos` e `unidades_orgao` as colunas são `poder` e `esfera`. Na API de contratações os parâmetros continuam `poderId` e `esferaId`. Letras, não IDs numéricos.

| `poder` | Significado | `esfera` | Significado |
|---|---|---|---|
| `E` | Executivo | `F` | Federal |
| `L` | Legislativo | `E` | Estadual |
| `J` | Judiciário | `M` | Municipal |
| `M` | Ministério Público | `D` | Distrital (DF) |
| `N` | Não se aplica | `N` | Não se aplica |

| `situacaoCompraId` | Significado | `modalidadeId` | Significado |
|---|---|---|---|
| `1` | Divulgada no PNCP | `1` | Leilão |
| `2` | Revogada | `2` | Diálogo Competitivo |
| `3` | Anulada | `3` | Concurso |
| `4` | Suspensa | `4` | Concorrência |
| | | `5` | Concurso (específico) / Chamamento Público |
| | | `6` | Pregão |
| | | `7` | Dispensa de Licitação |
| | | `8` | Inexigibilidade |
| | | `9` | Credenciamento |
| | | `10` | Pré-qualificação |

| `modoDisputaId` | Significado | `tipoInstrumentoConvocatorioCodigo` | Significado | `fontesOrcamentarias` | Significado |
|---|---|---|---|---|---|
| `1` | Aberto | `1` | Edital | `1` | Recursos do Tesouro |
| `2` | Fechado | `2` | Aviso de Contratação Direta | `2` | Outras Fontes |
| `3` | Aberto e Fechado | `3` | Ato de Inexigibilidade | | |
| `4` | Fechado e Aberto | `4` | Ato de Dispensa | | |
| `5` | Não se aplica | | | | |
