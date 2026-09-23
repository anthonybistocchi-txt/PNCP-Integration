# Implementação do módulo PCA — PNCP Integration Backend

## 1. Objetivo

Implementar no backend do projeto **PNCP Integration** o suporte completo à ingestão, persistência, consulta e busca de dados de **Plano de Contratações Anual (PCA)** provenientes do PNCP.

A implementação deve seguir rigorosamente a arquitetura e os padrões já existentes no projeto.

O objetivo não é simplesmente criar endpoints que repassem os dados do PNCP.

O sistema deve:

1. sincronizar dados do PCA do PNCP;
2. persistir os dados no PostgreSQL;
3. manter o PostgreSQL como fonte de verdade para leitura;
4. utilizar filas/Redis para ingestão assíncrona;
5. permitir consultas eficientes;
6. permitir busca textual nos itens do PCA;
7. preparar a estrutura para posteriormente relacionar PCA → contratação;
8. preservar a alta performance da aplicação;
9. manter a sincronização resiliente a 429, timeout, 5xx e indisponibilidade do PNCP;
10. manter observabilidade e rastreabilidade semelhantes à sincronização atual de contratações.

O projeto possui atualmente uma separação clara entre fluxo de leitura síncrono e fluxo de sincronização assíncrono. O fluxo de leitura utiliza exclusivamente PostgreSQL, enquanto a sincronização utiliza jobs e consulta o PNCP em background. Essa separação deve ser preservada.

---

# 2. Regra principal da implementação

Antes de escrever qualquer código:

## O agente DEVE estudar o código existente.

Não criar uma arquitetura paralela.

Primeiro identificar:

* Commands existentes de sincronização;
* Jobs existentes;
* Actions;
* Services;
* Repositories;
* DTOs;
* Clients HTTP;
* classes de integração com PNCP;
* configuração `config/pncp.php`;
* migrations;
* Models;
* FormRequests;
* Resources;
* tratamento de erros;
* mecanismos de retry;
* circuit breaker;
* throttle;
* logs;
* testes;
* convenções de nomes;
* índices existentes;
* queries atuais;
* estratégia de upsert;
* estratégia de paginação;
* estratégia de processamento em lote.

Sempre que existir um padrão equivalente, reutilizar o padrão.

Não criar uma segunda maneira de fazer a mesma coisa.

---

# 3. Pesquisa da documentação PNCP

Utilizar como fonte oficial principal:

https://pncp.gov.br/manual/pt-br/latest/index.html

O manual atual apresenta o domínio de Plano de Contratação e os endpoints relacionados ao PCA.

Endpoints relevantes identificados:

```text
GET /v1/orgaos/{cnpj}/pca/{ano}/consolidado

GET /v1/orgaos/{cnpj}/pca/{ano}/consolidado/unidades

GET /v1/orgaos/{cnpj}/pca/{ano}/{sequencial}/consolidado

GET /v1/orgaos/{cnpj}/pca/{ano}/{sequencial}/valorcategoriaitem

GET /v1/orgaos/{cnpj}/pca/{ano}/{sequencial}/itens

GET /v1/orgaos/{cnpj}/pca/{ano}/{sequencial}/itens/contratacao

GET /v1/orgaos/{cnpj}/pca/{ano}/valorcategoriaitem
```

A documentação confirma que o endpoint consolidado consulta o PCA de determinado órgão e ano.

O endpoint de itens recupera os itens pertencentes ao PCA de uma unidade e ano.

Os dados dos itens incluem informações importantes para o produto, como:

* categoria;
* catálogo;
* classificação;
* código do item;
* descrição;
* quantidade;
* unidade;
* valor unitário;
* valor total;
* valor orçamentário;
* data desejada;
* unidade requisitante;
* grupo de contratação futura;
* data de publicação;
* data de inclusão;
* data de atualização.

Esses campos estão documentados pelo PNCP.

O endpoint de valores por categoria fornece agregações de quantidade e valor total.

---

# 4. Não implementar automaticamente todos os endpoints de escrita

O objetivo deste projeto é consumir o PNCP.

Portanto:

## NÃO implementar:

```text
POST /pca
PUT /pca
DELETE /pca
```

a menos que exista uma necessidade explícita no produto.

O endpoint de inserção do PNCP é destinado ao envio de planos ao PNCP e possui requisitos próprios, inclusive limite de itens e autenticação. Não faz parte do objetivo desta implementação.

O projeto deve ser tratado como consumidor/sincronizador.

---

# 5. Modelagem do banco

Criar uma modelagem normalizada e orientada ao uso da aplicação.

Sugestão conceitual:

```text
pca_plans
    │
    └── pca_items
```

Possivelmente outras tabelas auxiliares caso o código existente demonstre necessidade.

## 5.1 Tabela `pca_plans`

Representa um PCA de uma unidade administrativa.

Campos conceituais:

```text
id
cnpj_orgao
codigo_unidade
ano_pca
sequencial_pca

dados principais retornados pelo PNCP

data_publicacao_pncp
data_inclusao
data_atualizacao

created_at
updated_at
```

O agente deve confirmar os campos exatos retornados pelo PNCP antes de definir a migration definitiva.

Não inventar campos que não tenham utilidade.

---

# 6. Identidade do PCA

Não utilizar somente `id` interno para identificar um PCA.

Criar uma chave única baseada na identidade do PNCP.

A combinação esperada deve ser analisada conforme o contrato da API:

```text
cnpj_orgao
ano_pca
sequencial_pca
```

Se o retorno demonstrar que `codigo_unidade` também faz parte da identidade operacional, avaliar inclusão no índice/chave.

A regra deve ser definida após análise dos payloads reais.

---

# 7. Tabela `pca_items`

Representar os itens individuais do PCA.

Campos esperados/conceituais:

```text
id

pca_plan_id

numero_item

categoria_item_pca
categoria_item_pca_nome

catalogo_id
classificacao_catalogo_id

classificacao_superior_codigo
classificacao_superior_nome

pdm_codigo
pdm_nome

codigo_item
descricao_item

unidade_fornecimento

quantidade
valor_unitario
valor_total
valor_orcamento_exercicio

data_desejada

unidade_requisitante

grupo_contratacao_codigo
grupo_contratacao_nome

data_publicacao_pncp
data_inclusao
data_atualizacao

created_at
updated_at
```

Os nomes exatos devem seguir as convenções já utilizadas pelo projeto.

Se o projeto utiliza `camelCase` nos DTOs e `snake_case` no banco, manter essa separação.

---

# 8. Tipos de dados PostgreSQL

Não usar tipos genéricos sem avaliar impacto.

Para valores financeiros:

```text
numeric(precision, scale)
```

ou o padrão já utilizado pelo projeto.

Para quantidades:

```text
numeric(...)
```

porque o PNCP permite valores decimais.

Não utilizar `float` para valores monetários.

Para datas:

```text
date
```

quando o campo representa somente data.

Usar:

```text
timestamp/timestamptz
```

somente quando o PNCP realmente fornecer informação temporal.

---

# 9. Índices — prioridade máxima

Performance de banco é um requisito fundamental deste projeto.

Não criar índices apenas porque "parecem úteis".

Cada índice deve estar associado a uma consulta real.

Antes de criar os índices:

1. identificar consultas esperadas;
2. analisar filtros;
3. analisar ordenação;
4. analisar cardinalidade;
5. analisar seletividade;
6. analisar joins;
7. analisar paginação;
8. utilizar `EXPLAIN (ANALYZE, BUFFERS)` em queries representativas.

---

# 10. Índices obrigatórios a avaliar

## PCA

Criar índice para o padrão:

```text
cnpj_orgao + ano_pca
```

e uma constraint/index unique para a identidade do PCA.

Exemplo conceitual:

```sql
UNIQUE (
    cnpj_orgao,
    ano_pca,
    sequencial_pca
)
```

A composição definitiva deve ser confirmada com os dados reais.

---

# 11. Índices dos itens

Os principais padrões de consulta esperados:

```text
pca_plan_id
```

```text
pca_plan_id + numero_item
```

```text
pca_plan_id + categoria_item_pca
```

```text
pca_plan_id + data_desejada
```

```text
pca_plan_id + grupo_contratacao_codigo
```

Não criar todos indiscriminadamente.

Medir primeiro.

---

# 12. Full Text Search

O sistema possui como ponto forte busca textual utilizando PostgreSQL + GIN.

O PCA deve aproveitar essa mesma estratégia.

Criar uma coluna de busca derivada, por exemplo:

```text
search_vector
```

ou seguir exatamente a solução já existente nas contratações.

A busca deve considerar principalmente:

```text
descricao_item
classificacao_superior_nome
grupo_contratacao_nome
unidade_requisitante
```

e outros campos textuais relevantes.

Não simplesmente indexar cada `varchar`.

Utilizar PostgreSQL Full Text Search.

Exemplo conceitual:

```sql
to_tsvector(
    'portuguese',
    coalesce(descricao_item, '') || ' ' ||
    coalesce(classificacao_superior_nome, '') || ' ' ||
    coalesce(grupo_contratacao_nome, '')
)
```

Com índice:

```sql
CREATE INDEX ...
ON pca_items
USING GIN(search_vector);
```

Porém:

## IMPORTANTE

Primeiro verificar como a aplicação atual implementa FTS nas contratações.

Se já existir:

```text
tsvector
GIN
ts_rank
websearch_to_tsquery
plainto_tsquery
```

ou outra estratégia:

**reutilizar a mesma estratégia.**

Não criar uma segunda implementação de Full Text Search.

---

# 13. Trigram / ILIKE

Não substituir Full Text Search por:

```sql
ILIKE '%notebook%'
```

em grandes volumes.

Se houver necessidade real de autocomplete/fuzzy search, avaliar separadamente:

```text
pg_trgm
GIN/GiST
```

Mas somente após medir a necessidade.

---

# 14. Estratégia de sincronização

A sincronização do PCA deve seguir o mesmo conceito já utilizado:

```text
Scheduler
    ↓
Command
    ↓
Master Job
    ↓
Page Jobs
    ↓
PNCP Client
    ↓
Upsert em lote
```

Não fazer:

```text
foreach (...)
    model->save()
```

para milhares de registros.

Utilizar bulk upsert.

---

# 15. Criar comando dedicado

Seguir o padrão atual de:

```text
pncp:sync
```

Criar, caso faça sentido arquiteturalmente:

```text
pncp:sync-pca
```

ou integrar PCA ao mecanismo existente.

A decisão deve ser tomada depois de analisar o `pncp:sync` atual.

O comando deve aceitar parâmetros úteis, por exemplo:

```bash
php artisan pncp:sync-pca
```

Possíveis opções:

```bash
--ano=2027
--cnpj=...
--days=...
--force
```

Não adicionar opções sem necessidade.

---

# 16. Sincronização incremental

Evitar uma estratégia que obrigue a baixar todos os PCAs históricos diariamente.

O agente deve analisar quais informações de data o PNCP disponibiliza e implementar uma estratégia incremental.

O PCA possui:

```text
dataPublicacaoPncp
dataInclusao
dataAtualizacao
```

nos itens, por exemplo.

Utilizar essas informações quando forem adequadas para detectar alterações.

---

# 17. Idempotência

A sincronização deve ser idempotente.

Executar:

```text
sync PCA
sync PCA
sync PCA
```

não pode criar duplicações.

Usar:

```text
upsert
```

com chave natural do PNCP.

---

# 18. Não utilizar quantidade de INSERT como métrica

Seguir a mesma regra já utilizada na sincronização de contratações.

Um:

```text
upsert
```

pode atualizar um registro existente.

Portanto:

```text
insert_count
```

não representa necessariamente progresso real.

Registrar métricas separadas:

```text
items_received
items_inserted
items_updated
items_failed
pages_processed
pages_failed
```

quando isso for compatível com a infraestrutura existente.

---

# 19. Retry

Reutilizar a política atual de retry.

Não criar política diferente sem justificativa.

Manter:

```text
retryUntil
backoff
429 handling
5xx handling
timeout handling
```

conforme a implementação existente.

Se PCA tiver comportamento diferente do endpoint de contratações, documentar a razão.

---

# 20. Circuit breaker

Reutilizar o circuit breaker existente.

Não criar:

```text
PcaCircuitBreaker
```

se o projeto já possui um breaker global/por domínio adequado.

Caso o projeto tenha breakers separados por:

```text
listagem
documentos
```

avaliar se PCA deve possuir seu próprio escopo.

A decisão deve considerar:

* volume;
* rate limit;
* criticidade;
* possibilidade de PCA bloquear contratação.

---

# 21. Throttle

O PCA deve respeitar o mesmo limitador global utilizado pelo restante da aplicação.

Nunca criar um worker que possa ultrapassar o orçamento de requisições do PNCP.

Especialmente importante porque:

```text
Contratações
+
Documentos
+
PCA
```

podem rodar simultaneamente.

O throttle precisa ser global.

---

# 22. Concorrência

Não assumir que:

```text
3 workers
```

significa que o sistema pode fazer qualquer quantidade de requests.

O limite deve continuar sendo controlado pelo mecanismo global.

O agente deve testar:

```text
workers simultâneos
rate limit
429
retry
circuit breaker
```

---

# 23. Jobs

Seguir os padrões existentes.

Possível estrutura:

```text
SyncPcaDataJob
ProcessPcaPageJob
```

ou nomenclatura equivalente ao padrão atual.

Responsabilidades:

### `SyncPcaDataJob`

Responsável por:

* determinar o universo de sincronização;
* consultar metadados necessários;
* descobrir páginas;
* criar jobs filhos.

### `ProcessPcaPageJob`

Responsável por:

* consultar uma página;
* validar retorno;
* transformar dados;
* executar upsert;
* registrar progresso;
* lidar com retry.

Não colocar toda a lógica dentro do Command.

---

# 24. HTTP Client

Reutilizar o client HTTP existente.

Não fazer diretamente dentro do Job:

```php
Http::get(...)
```

se já existe uma camada de integração.

Criar métodos semanticamente claros:

```php
getPca()
getPcaUnits()
getPcaItems()
getPcaItemValuesByCategory()
getPcaItemsByFutureContract()
```

ou nomenclatura equivalente ao padrão existente.

Funções devem representar claramente a intenção.

Evitar nomes genéricos:

```php
getData()
fetch()
process()
handleData()
```

quando não deixam claro o domínio.

---

# 25. DTOs

Criar DTOs para separar:

```text
HTTP PNCP
      ↓
DTO
      ↓
Action
      ↓
Persistence
```

Exemplo conceitual:

```text
PcaPlanData
PcaItemData
PcaCategorySummaryData
```

Não passar arrays gigantes pelo sistema inteiro.

---

# 26. Actions

Criar Actions seguindo o padrão já existente.

Exemplo:

```text
SyncPcaPlanAction
SyncPcaItemAction
```

ou uma Action de bulk:

```text
SyncPcaItemsAction
```

A prioridade é bulk processing.

Se houver:

```text
1000 itens
```

não executar 1000 operações SQL individuais.

Preferir:

```text
1 bulk upsert
```

ou quantidade pequena de lotes.

---

# 27. Repository

O Repository deve existir se o projeto já utiliza esse padrão.

Responsabilidade:

```text
Repository
=
consulta/persistência
```

Não colocar regra de negócio no Repository.

Evitar:

```php
if (...)
    ...
```

com regras complexas dentro das queries.

---

# 28. Queries de leitura

O endpoint da aplicação nunca deve consultar o PNCP.

Fluxo obrigatório:

```text
HTTP
 ↓
FormRequest
 ↓
DTO
 ↓
Service
 ↓
Repository
 ↓
PostgreSQL
```

O PNCP somente é utilizado no fluxo de sincronização.

---

# 29. Endpoint público da aplicação

Criar endpoints próprios para PCA.

Sugestão:

```http
QUERY /api/v1/pca
```

ou seguir exatamente o padrão existente de `/contracts`.

Possíveis filtros:

```text
dataInicial
dataFinal
ano
cnpj
codigoUnidade
categoriaItem
uf
municipio
search
limit
cursor
```

Mas não implementar filtros que não possuam suporte eficiente no banco.

---

# 30. Busca por texto

Criar endpoint especializado para oportunidades de PCA, se fizer sentido.

Exemplo:

```http
QUERY /api/v1/pca/items
```

com:

```json
{
    "ano": 2027,
    "search": "notebook computador",
    "limit": 50,
    "cursor": null
}
```

O resultado deve ser ordenado por relevância quando houver pesquisa textual.

---

# 31. Cursor pagination

Não utilizar:

```sql
OFFSET
```

para grandes volumes.

Reutilizar a estratégia existente de cursor/keyset.

A paginação precisa possuir uma ordenação determinística.

Por exemplo:

```text
data_desejada
+
id
```

ou outra chave adequada.

Nunca ordenar apenas por uma coluna que possa conter muitos empates sem um segundo critério.

---

# 32. Query de busca

Para uma busca:

```text
"notebook"
```

o PostgreSQL deve conseguir:

```text
Index Scan
ou
Bitmap Index Scan
```

conforme o plano.

Não aceitar:

```text
Seq Scan
```

em uma tabela grande sem uma justificativa clara.

Testar com:

```sql
EXPLAIN (ANALYZE, BUFFERS)
```

---

# 33. Evitar N+1

Ao retornar:

```text
PCA
+
unidade
+
categoria
```

não fazer uma query por registro.

Evitar:

```php
foreach ($items as $item) {
    $item->category;
}
```

quando isso gerar N+1.

Usar:

```text
joins
eager loading
subqueries
```

conforme o caso.

Mas não carregar relacionamentos automaticamente sem necessidade.

---

# 34. SELECT explícito

Evitar:

```php
Model::query()->get();
```

quando a tabela possui muitas colunas.

Selecionar apenas os campos necessários.

Exemplo:

```php
->select([
    'id',
    'pca_plan_id',
    'numero_item',
    'descricao_item',
    'quantidade',
    'valor_total',
])
```

Isso reduz:

* I/O;
* memória;
* payload;
* tempo de hidratação.

---

# 35. Agregações

Para:

```text
valor por categoria
```

não carregar todos os itens para PHP e fazer:

```php
$items->groupBy(...)
```

Se a informação puder ser calculada no PostgreSQL:

```sql
GROUP BY categoria_item_pca
```

preferir o banco.

O próprio PNCP possui endpoint de agregação por categoria.

No sistema local, avaliar se essas agregações serão:

1. calculadas sob demanda;
2. materializadas;
3. armazenadas em tabela de resumo.

Escolher com base em volume e frequência de consulta.

---

# 36. Índices parciais

Avaliar índices parciais somente quando houver um predicado altamente recorrente.

Exemplo conceitual:

```sql
WHERE data_desejada IS NOT NULL
```

Não criar índice parcial simplesmente porque o PostgreSQL permite.

Cada índice adiciona:

* armazenamento;
* custo de INSERT;
* custo de UPDATE;
* custo de VACUUM;
* manutenção.

---

# 37. BRIN

Se determinadas colunas possuírem comportamento temporal e grande volume, avaliar BRIN.

Exemplo:

```text
data_publicacao_pncp
data_atualizacao
```

Mas comparar empiricamente com B-tree.

Não assumir que BRIN é sempre melhor.

---

# 38. Estatísticas e EXPLAIN

Depois da implementação, testar consultas reais com:

```sql
EXPLAIN (ANALYZE, BUFFERS, VERBOSE)
```

Avaliar:

* execution time;
* planning time;
* rows;
* actual rows;
* loops;
* shared hit;
* shared read;
* index scans;
* bitmap scans;
* sequential scans;
* sort;
* temporary files.

Documentar as decisões importantes.

---

# 39. Testes de banco

Criar testes que validem:

### Unicidade

Não duplicar PCA.

### Upsert

Executar sincronização duas vezes e garantir mesmo resultado.

### Atualização

Alterar dados simulados do PNCP e garantir atualização local.

### Itens

Garantir vínculo correto:

```text
PCA → items
```

### Busca

Garantir que:

```text
notebook
```

encontre:

```text
Notebook Dell
Computador portátil notebook
```

conforme a configuração de FTS.

### Paginação

Garantir:

```text
page 1
→ cursor
→ page 2
→ cursor
→ ...
```

sem:

* duplicação;
* registros perdidos;
* loop infinito.

---

# 40. Testes de integração com PNCP

Criar testes para:

```text
200
429
500
502
timeout
payload inválido
lista vazia
página inexistente
```

O comportamento deve seguir os mecanismos existentes de resiliência.

---

# 41. Observabilidade

Seguir os eventos existentes:

```text
PNCP_PCA_START
PNCP_PCA_PAGE_STARTED
PNCP_PCA_PAGE_FINISHED
PNCP_PCA_PAGE_FAILED
PNCP_PCA_FINISH
```

ou nomenclatura compatível com os logs existentes.

Registrar pelo menos:

```text
ano
cnpj
sequencial
page
items_received
items_processed
duration
attempt
error
```

Não registrar dados sensíveis desnecessariamente.

---

# 42. Métricas

Idealmente conseguir responder:

```text
Quantos PCAs foram processados?

Quantos itens foram recebidos?

Quantos foram inseridos?

Quantos foram atualizados?

Quantas páginas falharam?

Quantos retries aconteceram?

Quantos 429?

Quantos 5xx?

Quanto tempo levou?

Quantos PCAs estão atrasados?
```

---

# 43. Controle de gaps

A sincronização de PCA também deve possuir mecanismo de identificação de falhas.

Não depender somente do log.

Criar/estender estrutura de controle existente para saber:

```text
started
finished
failed
abandoned
```

e permitir detectar:

```text
PCA esperado
mas não processado
```

Se for possível determinar o universo esperado.

---

# 44. Backfill

Criar suporte para sincronização histórica.

Exemplo:

```bash
php artisan pncp:sync-pca --ano=2026
```

ou:

```bash
php artisan pncp:sync-pca --ano-inicial=2025 --ano-final=2027
```

O formato definitivo deve seguir a CLI existente.

O backfill precisa ser:

```text
idempotente
```

e limitado para não causar avalanche de requests.

---

# 45. Scheduler

Não simplesmente colocar o PCA para rodar junto da sincronização principal sem avaliar o orçamento de requests.

Analisar:

```text
contratações
documentos
PCA
```

e o limite global.

Definir janela própria ou integrar ao fluxo existente.

A decisão deve ser baseada em:

```text
volume real
tempo de processamento
rate limit
número de workers
```

---

# 46. Concorrência entre sincronizadores

Testar explicitamente:

```text
Contratação sync
+
Documento sync
+
PCA sync
```

simultaneamente.

Garantir que o sistema não produza:

```text
429 em cascata
```

ou:

```text
queue starvation
```

---

# 47. Melhorias de código existentes

Durante a implementação, o agente deve identificar pontos do código atual que possam ser melhorados.

Mas existe uma regra:

## Não transformar a implementação do PCA em uma grande refatoração.

Só refatorar código existente quando:

1. a mudança for claramente segura;
2. melhorar legibilidade;
3. eliminar duplicação real;
4. corrigir uma deficiência de arquitetura;
5. facilitar a implementação do PCA;
6. houver testes suficientes;
7. não alterar comportamento existente.

---

# 48. Nomes

Todos os nomes devem ser explícitos.

Evitar:

```php
$data
$item
$result
$response
$info
$obj
$tmp
```

quando o contexto não for óbvio.

Preferir:

```php
$pcaItems
$pcaItem
$pncpResponse
$paginationCursor
$syncResult
$categoryId
```

Funções:

Evitar:

```php
process()
handle()
sync()
getData()
```

Preferir:

```php
syncPcaItems()
fetchPcaItems()
persistPcaItems()
buildPcaSearchQuery()
getPcaItemsByCategory()
```

Os nomes devem permitir compreender o código sem precisar abrir a implementação da função.

---

# 49. Métodos pequenos

Evitar métodos de 200–500 linhas.

Separar responsabilidades.

Exemplo:

```text
handle()
    ↓
validateSynchronizationWindow()
    ↓
fetchPcaPage()
    ↓
mapPcaItems()
    ↓
upsertPcaItems()
    ↓
recordSynchronizationProgress()
```

Mas não criar abstrações artificiais apenas para reduzir quantidade de linhas.

---

# 50. Regras de negócio

Regra:

```text
Controller
```

não deve conhecer detalhes do PNCP.

Regra:

```text
Job
```

não deve conter toda a lógica de persistência.

Regra:

```text
Repository
```

não deve conter regra de negócio complexa.

Manter separação:

```text
Controller
    ↓
Request
    ↓
DTO
    ↓
Service
    ↓
Action/Repository
    ↓
Database
```

e:

```text
Scheduler
    ↓
Command
    ↓
Job
    ↓
PNCP Client
    ↓
Action
    ↓
Database
```

---

# 51. Relacionamento PCA → Contratação

A documentação do PNCP possui um endpoint específico para recuperar itens de PCA relacionados a uma contratação futura.

Esse relacionamento deve ser armazenado de forma que permita futura implementação de:

```text
PCA
 ↓
contratação futura
 ↓
contratação efetivamente publicada
```

Não criar uma associação artificial caso o PNCP não forneça identificador confiável.

Primeiro estudar os campos reais retornados.

---

# 52. Preparar o domínio para "oportunidades futuras"

A modelagem deve permitir futuramente:

```text
PCA item
 ↓
keyword matching
 ↓
relevância
 ↓
usuário interessado
 ↓
alerta
```

Por exemplo:

```text
Cliente:
"notebook"
"servidor"
"computador"

PCA:

"aquisição de computadores e notebooks"
```

O mesmo mecanismo de matching utilizado nas contratações deve poder ser reutilizado.

Não duplicar a implementação de matching.

---

# 53. Segurança

Manter:

```text
auth.api
```

e o padrão atual de autenticação.

Não criar endpoint de PCA público se os demais endpoints de dados não forem públicos.

Validar:

* limites;
* datas;
* ano;
* CNPJ;
* categoria;
* cursor;
* quantidade máxima.

Nunca permitir que uma requisição gere uma query ilimitada.

---

# 54. Rate limiting da API própria

Avaliar se endpoints de pesquisa de PCA precisam de rate limiting específico.

Principalmente porque:

```text
FTS
+
ordenação por relevância
+
joins
```

podem ser consultas relativamente pesadas.

---

# 55. Cache

Não adicionar cache automaticamente.

Primeiro medir PostgreSQL.

O PostgreSQL é a fonte de verdade e o ponto forte do projeto.

Se uma query:

```text
5 ms
```

não existe motivo para colocar Redis no meio.

Cache deve ser usado quando:

```text
query cara
+
alta repetição
+
baixa volatilidade
```

for demonstrado por métricas.

---

# 56. Performance como Definition of Done

A implementação somente estará concluída quando as queries críticas forem testadas.

Para cada consulta importante:

```text
filtro simples
filtro + categoria
full text search
full text + UF
full text + ano
paginação
agregação
```

executar:

```sql
EXPLAIN (ANALYZE, BUFFERS)
```

e registrar o plano.

---

# 57. Cenário de carga

Criar uma massa representativa.

Idealmente:

```text
100 mil itens
500 mil itens
1 milhão de itens
```

ou volume equivalente ao crescimento esperado.

Testar:

```text
search
pagination
filters
aggregations
```

em volume realista.

Não considerar uma query rápida com 1.000 registros como prova de performance.

---

# 58. Verificação de regressão

Depois da implementação:

```text
contratações
```

devem continuar com o mesmo comportamento.

Executar os testes existentes.

Comparar:

```text
tempo de queries existentes
```

antes/depois.

Verificar se novos índices não prejudicaram significativamente:

```text
INSERT
UPDATE
UPSERT
```

da sincronização atual.

---

# 59. Documentação

Atualizar o README/documentação do projeto contendo:

```text
PCA
```

com:

* arquitetura;
* sincronização;
* tabelas;
* comandos;
* scheduler;
* endpoints;
* filtros;
* paginação;
* índices importantes;
* estratégia de FTS;
* backfill;
* observabilidade.

Também documentar decisões não óbvias.

---

# 60. Entregáveis

Ao terminar, o agente deve entregar:

### Código

```text
Migrations
Models
DTOs
Services
Actions
Repositories
PNCP Client
Commands
Jobs
Requests
Resources
Tests
```

conforme necessário.

### Banco

```text
constraints
indexes
GIN
FTS
foreign keys
unique keys
```

### Sincronização

```text
scheduler
retry
backoff
throttle
circuit breaker
bulk upsert
gap detection
backfill
```

### API

```text
PCA endpoints
search
filters
cursor pagination
```

### Observabilidade

```text
structured logs
sync progress
errors
metrics
```

### Documentação

```text
README
API
sync
database
performance decisions
```

---

# 61. Critérios de aceite

A implementação será considerada concluída somente se:

## Funcionalidade

* [ ] PCA pode ser sincronizado do PNCP.
* [ ] PCAs são persistidos corretamente.
* [ ] Itens são persistidos corretamente.
* [ ] Reexecução não duplica registros.
* [ ] Alterações no PNCP atualizam o banco local.
* [ ] Consultas utilizam PostgreSQL.
* [ ] Nenhuma leitura da API da aplicação chama diretamente o PNCP.
* [ ] Busca textual funciona.
* [ ] Filtros funcionam.
* [ ] Cursor pagination funciona.
* [ ] Agregações funcionam.
* [ ] Relacionamentos estão corretos.

## Resiliência

* [ ] 429 possui retry.
* [ ] 5xx possui retry.
* [ ] timeout possui retry.
* [ ] circuit breaker funciona.
* [ ] throttle global é respeitado.
* [ ] falhas parciais não perdem todo o lote.
* [ ] jobs podem ser reexecutados com segurança.

## Banco

* [ ] Unique constraints corretas.
* [ ] Índices baseados em queries reais.
* [ ] FTS utiliza GIN.
* [ ] Queries críticas foram analisadas com `EXPLAIN ANALYZE`.
* [ ] Não existem N+1 queries.
* [ ] Paginação não utiliza OFFSET em tabelas grandes.
* [ ] Queries retornam somente as colunas necessárias.
* [ ] Bulk upsert é utilizado na sincronização.
* [ ] Não existem Sequential Scans inesperados em queries críticas.

## Qualidade

* [ ] nomes de classes claros;
* [ ] nomes de métodos claros;
* [ ] nomes de variáveis claros;
* [ ] responsabilidades separadas;
* [ ] código duplicado evitado;
* [ ] nenhuma abstração desnecessária;
* [ ] testes cobrindo cenários críticos;
* [ ] documentação atualizada.

---

# 62. Ordem recomendada de execução pelo agente

Não implementar tudo de uma vez.

Executar em fases.

## Fase 1 — Reconhecimento

1. Ler arquitetura atual.
2. Mapear classes.
3. Mapear fluxo de sincronização.
4. Mapear fluxo de leitura.
5. Mapear FTS.
6. Mapear índices.
7. Mapear paginação.
8. Mapear retry.
9. Mapear circuit breaker.
10. Mapear throttle.
11. Mapear testes.

Gerar um pequeno relatório antes de modificar o código.

---

## Fase 2 — Contrato PNCP

1. Estudar endpoints PCA.
2. Capturar payloads reais.
3. Mapear campos.
4. Definir identidade.
5. Definir campos obrigatórios/opcionais.
6. Definir tipos.
7. Identificar comportamento de paginação.
8. Identificar comportamento de atualização.

Não implementar baseado apenas em exemplos incompletos da documentação.

---

## Fase 3 — Banco

1. Criar migrations.
2. Criar constraints.
3. Criar foreign keys.
4. Criar índices.
5. Criar coluna/estrutura FTS.
6. Testar inserts.
7. Testar upserts.
8. Testar queries.
9. Rodar EXPLAIN.

---

## Fase 4 — Ingestão

Implementar:

```text
Client
 ↓
DTO
 ↓
Job
 ↓
Action
 ↓
Bulk Upsert
```

Adicionar:

```text
retry
throttle
breaker
logs
```

---

## Fase 5 — Consulta

Implementar:

```text
Request
 ↓
DTO
 ↓
Service
 ↓
Repository
 ↓
PostgreSQL
```

Com:

```text
filters
search
ranking
cursor
```

---

## Fase 6 — Performance

Testar:

```text
100k+
500k+
1M+
```

quando possível.

Executar:

```sql
EXPLAIN (ANALYZE, BUFFERS)
```

para cada query crítica.

Ajustar:

```text
indexes
query structure
ordering
pagination
select
joins
```

---

## Fase 7 — Resiliência

Simular:

```text
429
500
502
timeout
PNCP unavailable
partial page failure
worker restart
duplicate job
```

---

## Fase 8 — Integração

Testar:

```text
PCA sync
+
contract sync
+
document sync
```

simultaneamente.

Verificar rate limit e comportamento do Redis/queues.

---

## Fase 9 — Refatoração controlada

Somente agora revisar:

```text
duplicação
nomes
métodos
responsabilidades
classes
complexidade
```

Melhorar código existente somente quando houver benefício claro e risco baixo.

---

## Fase 10 — Documentação e entrega

Atualizar:

```text
README
API docs
database docs
sync docs
```

E gerar relatório final contendo:

```text
Arquivos criados
Arquivos alterados
Migrations
Índices
Queries críticas
EXPLAIN results
Jobs
Endpoints
Testes
Decisões arquiteturais
Possíveis melhorias futuras
```

---

# 63. Regra final para o agente

O objetivo NÃO é simplesmente fazer o PCA funcionar.

O objetivo é fazer o PCA funcionar **como parte natural da arquitetura existente**.

Prioridades:

```text
1. Correção
2. Consistência arquitetural
3. Performance
4. Resiliência
5. Legibilidade
6. Testabilidade
7. Observabilidade
8. Extensibilidade
```

Nunca sacrificar performance de banco por abstrações desnecessárias.

Nunca sacrificar clareza por "código genérico".

Nunca adicionar Redis/cache sem necessidade comprovada.

Nunca criar índices sem entender as queries.

Nunca usar OFFSET em grandes conjuntos de dados.

Nunca executar milhares de INSERTs individuais quando bulk upsert for possível.

Nunca chamar o PNCP durante uma consulta da API.

Sempre considerar o PostgreSQL como fonte de verdade para o fluxo de leitura.

Sempre utilizar nomes que expressem claramente a intenção.

Sempre medir queries críticas com:

```sql
EXPLAIN (ANALYZE, BUFFERS)
```

E, principalmente:

**antes de alterar qualquer parte importante do sistema, entender e respeitar o padrão já existente.**
