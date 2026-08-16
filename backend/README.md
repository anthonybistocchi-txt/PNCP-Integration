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
- **Tolerância a Falhas**: Cada operário roda em segundos. Se a rede oscilar, o Laravel isola a página que falhou e tenta novamente (até 5 vezes), sem prejudicar as demais páginas.
- **Bulk Upsert**: O operário baixa 500 licitações por vez e envia para a `SyncPublicContratationAction`. A Action executa um `PublicContratation::upsert()` no Postgres, inserindo e atualizando centenas de registros com apenas uma query SQL de alto desempenho.

---

## 🛠️ Tecnologias e Stack
- **PHP 8.2+**
- **Laravel 11**
- **PostgreSQL** (Armazenamento Relacional)
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

### 2. Configurando o Banco de Dados
Gere a estrutura inicial executando as migrations dentro do container do PHP:
```bash
docker-compose exec php php artisan migrate
```

### 3. Rodando o Worker da Fila
Para que a sincronização assíncrona funcione, o worker deve estar rodando em background:
```bash
docker-compose exec php php artisan queue:work
```
*(Para produção, utilize ferramentas como o Supervisor para manter o Worker de pé).*

### 4. Alimentando o Banco de Dados Manualmente
O sistema já está configurado para rodar a cada madrugada (`routes/console.php`), mas você pode forçar a sincronização de dados executando:
```bash
docker-compose exec php php artisan pncp:sync
```

---

## 📚 Endpoints da API

### `GET /api/contracts/public`
Busca licitações públicas salvas no banco de dados.

**Headers Requeridos:**
- `API_KEY`: *(Chave de Acesso configurada no `.env`)*
- `Accept`: `application/json`

**Query Params:**
- `dataInicial` (Obrigatório) - Formato `Ymd` (ex: 20260101)
- `dataFinal` (Obrigatório) - Formato `Ymd` (ex: 20260815)
- `codigoModalidadeContratacao` (Obrigatório) - ID da modalidade
- `uf` (Opcional) - Sigla do estado (ex: SP)
- `codigoMunicipioIbge` (Opcional) - Código de 7 dígitos do IBGE
- `pagina` (Opcional) - Paginação para o frontend
- `tamanhoPagina` (Opcional) - Quantidade de itens por página
