<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Escalonamento entre páginas
    |--------------------------------------------------------------------------
    |
    | Segundos entre o dispatch de cada ProcessPncpPageJob. Com poucos
    | workers a fila já limita a concorrência; 0 deixa os workers sempre
    | ocupados. Suba este valor se a API responder 429 com frequência.
    |
    */
    'page_stagger_seconds' => (int) env('PNCP_PAGE_STAGGER_SECONDS', 0),

    /*
    |--------------------------------------------------------------------------
    | Pausa entre documentos
    |--------------------------------------------------------------------------
    |
    | Milissegundos entre getArquivos na mesma página. 50 itens × 80 ms ≈ 4 s
    | de espera garantida por página (antes eram 10 s com 200 ms).
    |
    */
    'document_delay_ms' => (int) env('PNCP_DOCUMENT_DELAY_MS', 80),

    /*
    |--------------------------------------------------------------------------
    | Circuit breaker
    |--------------------------------------------------------------------------
    |
    | Minutos de pausa global após um 429. A API costuma liberar em < 1 min;
    | 2 min ainda dá margem sem estacionar o ciclo inteiro.
    |
    */
    'circuit_breaker_minutes' => (int) env('PNCP_CIRCUIT_BREAKER_MINUTES', 2),

];
