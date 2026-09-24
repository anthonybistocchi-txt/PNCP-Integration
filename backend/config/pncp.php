<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Escalonamento entre páginas
    |--------------------------------------------------------------------------
    |
    | Segundos entre o dispatch de cada ProcessPncpPageJob. Com poucos
    | workers a fila já limita a concorrência; 0 deixa os workers sempre
    | ocupados. O limitador de taxa global abaixo é quem define o teto real.
    |
    */
    'page_stagger_seconds' => (int) env('PNCP_PAGE_STAGGER_SECONDS', 0),

    /*
    |--------------------------------------------------------------------------
    | Escalonamento entre modalidades na Fase 2 (documentos)
    |--------------------------------------------------------------------------
    |
    | Minutos de espera adicionados por posição na fila do dia. Sem isso,
    | modalidades que terminam a listagem quase juntas (o caso comum, já que
    | todas são disparadas no mesmo horário) despacham SyncPncpDocumentsJob
    | quase juntas, gerando um pico concentrado de chamadas a /arquivos — foi
    | isso que, em 05/09, colocou o circuit breaker em nível máximo por ~6h.
    |
    */
    'documents_stagger_minutes' => (int) env('PNCP_DOCUMENTS_STAGGER_MINUTES', 8),

    /*
    |--------------------------------------------------------------------------
    | Pausa entre documentos
    |--------------------------------------------------------------------------
    |
    | Milissegundos entre getArquivos na mesma página. Substituído pelo
    | limitador de taxa global (pncp.throttle); manter os dois contaria a
    | espera em dobro. Mantido em 0 e disponível como escape hatch.
    |
    */
    'document_delay_ms' => (int) env('PNCP_DOCUMENT_DELAY_MS', 0),

    /*
    |--------------------------------------------------------------------------
    | Teto por modalidade na varredura de documentos pendentes
    |--------------------------------------------------------------------------
    |
    | A varredura deixou de ser 2 rajadas diárias e passou a ser drenagem
    | contínua (ver routes/console.php). Isso inverte o dimensionamento: o teto
    | não precisa mais cobrir o passivo de um dia inteiro numa execução — ele
    | precisa caber no orçamento do limitador global dentro de UMA janela.
    |
    | A conta que amarra os três números:
    |
    |     teto × modalidades_com_volume ≤ intervalo × 1.800 req/h
    |
    | Com intervalo de 20 min o orçamento teórico é de 600 requisições por
    | janela. As modalidades com volume real são ~4 (6, 8, 9 e 4); as outras
    | voltam `empty` em milissegundos. 200 × 4 = 800 passa um pouco do teto
    | “limpo” — o limitador global serializa o excesso e a janela estica, o
    | que é aceitável enquanto houver passivo grande (backfill / outage). Se
    | a fila de documentos começar a empilhar lote morrendo de retryUntil
    | com o PNCP saudável, baixe este número (não o intervalo).
    |
    | Média histórica: ~12.200 documentos/dia ÷ 72 janelas ≈ 170 por janela.
    | 200 dá folga para drenar passivo além do fluxo do dia
    | (72 × 200 = 14.400/modalidade/dia).
    |
    | O teto antigo (2.000) era o motivo de a modalidade 6 nunca convergir:
    | 5.292 registros/dia contra 2 execuções × 2.000 = 4.000 de capacidade
    | máxima garantiam passivo crescente de ~1.300/dia, mesmo com 100% de
    | sucesso.
    |
    */
    'pending_documents_max_per_run' => (int) env('PNCP_PENDING_DOCS_MAX_PER_RUN', 200),

    /*
    |--------------------------------------------------------------------------
    | Intervalo da drenagem e escalonamento entre modalidades
    |--------------------------------------------------------------------------
    |
    | `pending_documents_interval_minutes` existe para documentar/validar a
    | conta acima; quem agenda de fato é routes/console.php. Mantenha os dois
    | em sincronia ao mexer.
    |
    | O stagger da drenagem é próprio, e pequeno: o de 8 min da Fase 2 (abaixo)
    | existe para espalhar um pico de volume diário inteiro, mas aqui 10
    | modalidades × 8 min = 72 min estourariam a própria janela de 20 min — as
    | últimas modalidades seriam despachadas depois de a janela seguinte já ter
    | começado. 1 min espalha o trabalho de banco dos Jobs Mestres sem sair da
    | janela; o ritmo das chamadas HTTP quem dita é o limitador global.
    |
    */
    'pending_documents_interval_minutes' => (int) env('PNCP_PENDING_DOCS_INTERVAL_MINUTES', 20),
    'pending_documents_stagger_minutes'  => (int) env('PNCP_PENDING_DOCS_STAGGER_MINUTES', 1),

    /*
    |--------------------------------------------------------------------------
    | Timeouts e orçamento de retentativa HTTP
    |--------------------------------------------------------------------------
    |
    | O timeout é dimensionado pela latência das respostas bem-sucedidas
    | observadas em produção (5s, 27s, 29s), não pela latência da falha.
    | O erro de pool do PNCP chega em ~49s, então ele será cortado como
    | timeout — o sinal que importa é "falhou", não o texto do erro.
    |
    | 2 tentativas porque o PNCP é instável por requisição: já houve caso de
    | duas falhas seguidas e sucesso na terceira com os mesmos parâmetros.
    |
    | `document_timeout` era 10s por herança, não por medição — enquanto a
    | listagem sempre teve 40s. Em 15/09 isso cortou a faixa de 10-30s, que é
    | justamente onde o PNCP responde quando está sob carga: ~58% das vagas do
    | limitador da varredura das 09h viraram `cURL 28 after 10002ms`, e cada
    | uma dessas tentativas descartadas consumiu uma vaga sem gerar documento.
    | 30s cobre as respostas boas já observadas (27s, 29s) e ainda corta antes
    | do erro de pool (~49s), que não vale esperar.
    |
    | Atenção ao mexer: o pior caso por item é `retries × document_timeout +
    | retry_sleep_ms` (2 × 30s + 3s = 63s), e é ele que dimensiona
    | `documents_lote_size` contra o $timeout do ProcessPncpDocumentPageJob.
    |
    */
    'http' => [
        'connect_timeout' => (int) env('PNCP_HTTP_CONNECT_TIMEOUT', 5),
        'timeout'         => (int) env('PNCP_HTTP_TIMEOUT', 40),
        'document_timeout'=> (int) env('PNCP_HTTP_DOCUMENT_TIMEOUT', 30),
        'retries'         => (int) env('PNCP_HTTP_RETRIES', 2),
        'retry_sleep_ms'  => (int) env('PNCP_HTTP_RETRY_SLEEP_MS', 3000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tamanho do lote de documentos
    |--------------------------------------------------------------------------
    |
    | Quantos registros cada ProcessPncpDocumentPageJob busca. Dois limites
    | independentes puxam esse número para baixo:
    |
    | 1. Tempo de parede: pior caso é `lote × 63s` (ver bloco http acima) e
    |    precisa caber no $timeout de 900s do job — e, por consequência, no
    |    REDIS_QUEUE_RETRY_AFTER (960s). 10 × 63s = 630s, com folga para o
    |    limitador global e a escrita no banco. Era 50, que a 30s por item
    |    daria 1.575s e passaria a ser morto pelo worker no meio do lote.
    |
    | 2. Retrabalho na retentativa: o lote é a unidade de release. Mesmo com a
    |    persistência parcial do ProcessPncpDocumentPageJob, lote menor = menos
    |    itens em jogo por falha. Com lote 50 e 30% de falha por item, a chance
    |    de um lote fechar era 0,7^50 ≈ 0,00002% — foi isso que produziu os
    |    contadores de `attempt: 31..51` em 15/09.
    |
    */
    'documents_lote_size' => (int) env('PNCP_DOCUMENTS_LOTE_SIZE', 10),

    /*
    |--------------------------------------------------------------------------
    | Limitador de taxa global
    |--------------------------------------------------------------------------
    |
    | Intervalo mínimo entre requisições, somando todos os workers e os dois
    | endpoints (listagem e documentos): só 1 requisição é admitida por janela
    | de N segundos, sem rajada. Em 05/09 e 06/09, mesmo com poucas
    | requisições por segundo (ex.: 8 em 14s), o WAF do PNCP disparou 429 —
    | o gatilho parece ser a concentração, não só o volume total.
    |
    | Se ainda aparecer 429 com isso, subir o intervalo (ex.: 3 ou 4). Se
    | ficar limpo por alguns dias e quisermos mais velocidade, baixar para 1.
    |
    */
    'throttle' => [
        'min_interval_seconds' => (int) env('PNCP_MIN_INTERVAL_SECONDS', 2),
        'block_seconds'        => (int) env('PNCP_THROTTLE_BLOCK_SECONDS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Circuit breaker
    |--------------------------------------------------------------------------
    |
    | Limiares diferentes por tipo de erro: um 500 solto não pausa nada (a API
    | é instável por requisição), mas falhas consecutivas pausam. Um 429 pausa
    | de imediato, porque é a API pedindo explicitamente para parar.
    |
    | As escalas são em minutos e sobem a cada nova abertura sem sucesso no
    | meio. A janela ruim observada em produção durou ~26 minutos.
    |
    */
    'breaker' => [
        'server_error_threshold' => (int) env('PNCP_BREAKER_5XX_THRESHOLD', 3),
        'server_error_backoff'   => [1, 3, 10, 20],
        'rate_limit_backoff'     => [2, 5, 10, 20],
        'probe_lock_seconds'     => (int) env('PNCP_BREAKER_PROBE_SECONDS', 120),

        // Válvula de escape: em 05/09 o breaker ficou preso no nível máximo
        // (20 min) por ~6h porque cada sondagem falha renovava o TTL do nível.
        // A partir deste tanto de sondagens falhas seguidas no nível máximo,
        // a pausa passa a ser esta (bem maior), reduzindo ruído/carga durante
        // uma degradação real e sinalizando o incidente com Log::critical.
        'prolonged_outage_threshold'      => (int) env('PNCP_BREAKER_PROLONGED_OUTAGE_THRESHOLD', 3),
        'prolonged_outage_backoff_minutes'=> (int) env('PNCP_BREAKER_PROLONGED_OUTAGE_BACKOFF_MINUTES', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Janela total de retentativa
    |--------------------------------------------------------------------------
    |
    | release() consome tentativa no Laravel, então sem um limite por tempo um
    | job que apenas espera o circuit breaker morreria de MaxAttemptsExceeded
    | sem ter falhado por conta própria. Com retryUntil definido o $tries é
    | ignorado e quem manda é o relógio.
    |
    | Separado por escopo porque os dois lados têm economias opostas, e o
    | valor único de 6h estava errado para os dois ao mesmo tempo:
    |
    | LISTAGEM (12h) — é barata (só ~244 páginas/dia, ~8 min de limitador) e
    | insubstituível: se uma página morre, aquele pedaço do dia não entra no
    | banco e nenhum ciclo futuro volta a olhar para ele, então a Fase 2 e a
    | drenagem nunca sabem que ele existiu. Em 15/09 as páginas das
    | modalidades 4/6/8/9 morreram às 11:17 e 12:29-12:52, exatamente 6h
    | depois do início, no meio de pausas de 1h do breaker — a listagem nunca
    | terminou e a Fase 2 dessas modalidades nunca disparou. 12h absorvem o
    | padrão de 3-4 outages de 1h já observado e ainda morrem bem antes do
    | ciclo seguinte (00:45 + 12h = 12:45).
    |
    | DOCUMENTOS (2h) — é o oposto: cada lote é pequeno e totalmente
    | recuperável, porque os registros continuam com `documents_fetched_at`
    | nulo e a próxima janela da drenagem os repesca em ≤20 min. Insistir 6h
    | contra um PNCP fora do ar só ocupa worker e queima vaga do limitador
    | refazendo trabalho. 2h dá ~6x de margem sobre a espera real de um lote
    | numa janela saudável, e o abandono deixou de ser perda de dado.
    |
    */
    'retry_until_hours' => [
        'listagem'   => (int) env('PNCP_RETRY_UNTIL_HOURS_LISTAGEM', 12),
        'documentos' => (int) env('PNCP_RETRY_UNTIL_HOURS_DOCUMENTOS', 2),
    ],

    /*
    |--------------------------------------------------------------------------
    | Jitter dos reagendamentos
    |--------------------------------------------------------------------------
    |
    | Segundos aleatórios somados ao release para que as dezenas de páginas
    | represadas não voltem todas no mesmo instante.
    |
    */
    'jitter' => [
        'resume_min'  => 5,
        'resume_max'  => 45,
        'contend_min' => 30,
        'contend_max' => 90,
    ],

    /*
    |--------------------------------------------------------------------------
    | Compatibilidade
    |--------------------------------------------------------------------------
    |
    | Pausa fixa após 429, usada como fallback quando o PNCP não envia o
    | header Retry-After e ainda não há nível de escalonamento registrado.
    |
    */
    'circuit_breaker_minutes' => (int) env('PNCP_CIRCUIT_BREAKER_MINUTES', 2),

];
