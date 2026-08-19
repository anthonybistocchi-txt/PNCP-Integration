# PNCP Integration - Backend

Este é o backend da aplicação **PNCP Integration**, responsável por fornecer uma API de alta performance para consulta de licitações e contratos públicos, com sincronização assíncrona da API do Portal Nacional de Contratações Públicas (PNCP).

## 🏛️ Arquitetura

O sistema é dividido em dois fluxos isolados que não interferem um no outro:

### 1. Fluxo de Leitura (Síncrono/Frontend)
Focado em latência ultrabaixa (< 20ms). 
- O frontend consome os dados do nosso banco de dados PostgreSQL, que atua como a única **Fonte de Verdade**.
- **Segurança**: As rotas são protegidas por um middleware de autenticação (`auth.api`) que exige o Header `API_KEY`.
- **Validação**: Dados chegam na rota, passam pelo `GetPublicContratationsRequest`, e são convertidos rigorosamente em um `DTO`.
- O `Service` delega para o `Repository` que retorna os dados filtrados.
- **Importante:** A leitura **NUNCA** faz requisições externas para o Governo, garantindo que o usuário não sofra com lentidão ou timeouts.

### 2. Fluxo de Sincronização (Assíncrono/Background)
Focado em alto volume de ingestão de dados.
- O `Scheduler` (Cron) aciona o comando `pncp:sync` diariamente de madrugada.
- O Command despacha um Job Mestre: `SyncPncpDataJob`.
- **Padrão Fan-Out**: O Mestre consulta a API do PNCP apenas para descobrir o total de páginas existentes. Em seguida, ele cria e enfileira um operário (`ProcessPncpPageJob`) para *cada página* existente.
- **Tolerância a Falhas e Logs**: O sistema captura erros e "Poisoned Jobs" silenciosamente. Se a rede oscilar, o Laravel tenta novamente (até 5 vezes). Erros de Timeout, Rate Limiting (429) e Falhas Fatais geram Logs Estruturados em JSON para facilitar a depuração via DataDog/Elasticsearch sem poluir o log com Stack Traces gigantes.
- **Circuit Breaker**: Integrado com Redis, o sistema detecta erros `429 Too Many Requests` (Rate Limit da API do Governo) e imediatamente "abre o disjuntor", devolvendo os Jobs para a fila com uma pausa inteligente de 5 minutos.
- **Bulk Upsert**: O operário baixa páginas de 50 licitações do Governo e envia para a `SyncPublicContratationAction`. A Action executa um `PublicContratation::upsert()` no Postgres de forma massiva.

---

## 🛠️ Tecnologias e Stack
- **PHP 8.2+**
- **Laravel 11**
- **PostgreSQL** (Armazenamento Relacional)
- **Redis** (Cache em memória de altíssima velocidade para Rate Limiting)
- **Docker & Docker Compose** (Containerização Completa)
- **Padrões**: DTOs (Data Transfer Objects), FormRequests, Services, Repositories, Resources, Fan-Out Jobs.

---

## 🚀 Como Rodar o Projeto

### 1. Subindo os Containers
O projeto possui um ambiente conteinerizado pronto para uso:
```bash
docker-compose up -d
```
Isso iniciará:
- Container do PHP-FPM / Nginx (Backend Laravel)
- Container do PostgreSQL (Banco de Dados)
- Container do Redis (Cache/Circuit Breaker)

### 2. Configurando o Banco de Dados
Gere a estrutura inicial e o seu Usuário Admin (com a `API_KEY` definida no seu `.env`) executando:
```bash
docker-compose exec php php artisan migrate --seed
```

### 3. Rodando o Worker da Fila
Para que a sincronização assíncrona funcione, o worker deve estar rodando em background:
```bash
docker-compose exec php php artisan queue:work
```
*(Para produção, utilize ferramentas como o Supervisor para manter o Worker de pé).*

### 4. Alimentando o Banco de Dados Manualmente
```bash
docker-compose exec php php artisan pncp:sync
```
*(Obs: O sincronizador Mestre atual foi configurado para focar inicialmente e varrer apenas a Modalidade 6 - Pregão).*

---

## 📚 Endpoints da API

### `QUERY /api/contracts`
Endpoint projetado para suportar buscas complexas enviando o payload via `body` ao invés de sobrecarregar a URL. Utiliza o método HTTP `QUERY`.

**Headers Requeridos:**
- `API_KEY`: *(Chave de Acesso configurada no `.env`)*
- `Accept`: `application/json`

**Body Params (JSON):**
```json
{
  "dataInicial": "20260101",
  "dataFinal": "20260815",
  "codigoModalidadeContratacao": 6,
  "tipoData": "publicacao", 
  "uf": "SP",
  "codigoMunicipioIbge": 3543402,
  "pagina": 1,
  "tamanhoPagina": 30,
  "poderId": "E",
  "esferaId": "M",
  "situacaoCompraId": 1,
  "modoDisputaId": 1,
  "tipoInstrumentoConvocatorioCodigo": 1,
  "fontesOrcamentarias": 2
}
```
*(Obs: `dataInicial`, `dataFinal` e `codigoModalidadeContratacao` são obrigatórios. O limite máximo permitido para o `tamanhoPagina` é de 30 registros por requisição para evitar travamentos).*

**Exemplo de Resposta (JSON):**
A API implementa metadados de paginação padronizados no escopo global (fora da chave `data`):
```json
{
  "data": [
    {
      "numeroControle": "12345/2026",
      "processo": "0001/2026",
      ...
    }
  ],
  "count": 1450,
  "total_pages": 49
}
```

**Sobre o parâmetro `tipoData`:**
- `"publicacao"` (Padrão): A busca pelas datas informadas será baseada na data original de publicação do edital.
- `"atualizacao"`: A busca pelas datas informadas olhará para as licitações que **sofreram alterações** no PNCP nesse intervalo (ex: um edital de janeiro que foi atualizado hoje).

---

## 🗂️ Tabelas de Domínio (Dicionário de Dados PNCP)

Para facilitar a construção dos filtros no JSON, abaixo estão os principais domínios mapeados da API do PNCP para consulta:

### `poderId`
Identifica a qual poder da república a entidade pertence.
* `E`: Executivo
* `L`: Legislativo 
* `J`: Judiciário 
* `N`: Não se aplica *(geralmente prefeituras com omissões de cadastro ou natureza atípica)*

### `esferaId`
Esfera governamental.
* `F`: Federal
* `E`: Estadual
* `M`: Municipal
* `D`: Distrital (Distrito Federal)
* `N`: Não se aplica

### `situacaoCompraId`
Status atual do processo da contratação.
* `1`: Divulgada no PNCP *(ativa/visível)*
* `2`: Revogada *(cancelada pelo órgão por interesse público)*
* `3`: Anulada *(cancelada por ilegalidade ou vício jurídico)*
* `4`: Suspensa *(pausada temporariamente, geralmente por decisão judicial)*

### `modalidadeId` / `codigoModalidadeContratacao`
A regra sob a qual o processo ocorre.
* `1`: Leilão
* `2`: Diálogo Competitivo
* `3`: Concurso
* `4`: Concorrência
* `5`: Concurso (específico) / Chamamento Público
* `6`: Pregão (Eletrônico ou Presencial)
* `7`: Dispensa de Licitação
* `8`: Inexigibilidade
* `9`: Credenciamento
* `10`: Pré-qualificação

### `modoDisputaId`
* `1`: Aberto *(Licitantes enviam lances públicos e sucessivos)*
* `2`: Fechado *(Propostas sigilosas e abertas todas de uma vez)*
* `3`: Aberto e Fechado *(Lances abertos seguidos de oferta final fechada)*
* `4`: Fechado e Aberto *(Propostas iniciais fechadas, melhores vão para lances abertos)*
* `5`: Não se aplica *(Contratações diretas como dispensas)*

### `tipoInstrumentoConvocatorioCodigo`
Documento oficial que formaliza a abertura.
* `1`: Edital
* `2`: Aviso de Contratação Direta (Dispensa/Inexigibilidade)
* `3`: Ato de Inexigibilidade
* `4`: Ato de Dispensa

### `fontesOrcamentarias` (Código)
* `1`: Recursos do Tesouro *(Dinheiro do próprio caixa do ente, como impostos)*
* `2`: Outras Fontes *(Transferências, convênios, emendas ou fundos setoriais)*
