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
    */
    'http' => [
        'connect_timeout' => (int) env('PNCP_HTTP_CONNECT_TIMEOUT', 5),
        'timeout'         => (int) env('PNCP_HTTP_TIMEOUT', 40),
        'document_timeout'=> (int) env('PNCP_HTTP_DOCUMENT_TIMEOUT', 10),
        'retries'         => (int) env('PNCP_HTTP_RETRIES', 2),
        'retry_sleep_ms'  => (int) env('PNCP_HTTP_RETRY_SLEEP_MS', 3000),
    ],

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
    */
    'retry_until_hours' => (int) env('PNCP_RETRY_UNTIL_HOURS', 6),

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
