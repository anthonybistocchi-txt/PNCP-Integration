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
    | Teto de requisições por segundo somando todos os workers e os dois
    | endpoints (listagem e documentos). O WAF do PNCP dispara por pico, e a
    | busca de anexos é ~98% do tráfego: 50 chamadas por página.
    |
    | Se aparecer 429 mesmo assim, baixar para 4. Se ficar limpo e quisermos
    | mais velocidade, subir para 8.
    |
    */
    'throttle' => [
        'max_per_second' => (int) env('PNCP_MAX_REQUESTS_PER_SECOND', 6),
        'block_seconds'  => (int) env('PNCP_THROTTLE_BLOCK_SECONDS', 10),
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
