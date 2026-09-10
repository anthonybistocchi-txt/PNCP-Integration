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
    | O retryUntil de cada lote (6h, ver `retry_until_hours`) grava a janela
    | máxima antes do job morrer de MaxAttemptsExceeded sem ter falhado por
    | conta própria. O limitador global permite ~1.800 requisições/hora, ou
    | seja ~10.800 numa janela de 6h.
    |
    | O valor original (1.000) foi calibrado para o pior caso teórico das 10
    | modalidades cheias ao mesmo tempo (10.000 ≤ 10.800). Na prática, só
    | 3-4 modalidades (6, 7, 8 e 9 — Pregão, Dispensa, Inexigibilidade,
    | Credenciamento) acumulam volume relevante; as demais ficam em zero na
    | maioria dos dias. Em 10/09/2026 o run das 09h processou 4.139 registros
    | em 2h47min (~2,4s/registro, já incluindo overhead de fila/circuit
    | breaker) e ainda sobraram ~3h13min de folga dentro da janela de 6h —
    | a folga estava sendo desperdiçada pelo teto, não pelo limitador.
    |
    | 2.000 cobre o pior caso realista (4 modalidades grandes cheias ao mesmo
    | tempo = 8.000 requisições ≈ 5,3h) com margem antes do limite de 6h.
    |
    */
    'pending_documents_max_per_run' => (int) env('PNCP_PENDING_DOCS_MAX_PER_RUN', 2000),

    /*
    |--------------------------------------------------------------------------
    | Limiar de alerta do passivo de documentos pendentes
    |--------------------------------------------------------------------------
    |
    | Usado por `pncp:monitorar-documentos-pendentes` (agendado às 23:45,
    | depois da varredura das 21h). Se uma modalidade ainda tiver mais que
    | isso de `documents_fetched_at IS NULL` a essa hora, é sinal de que o
    | teto por execução (acima) não está dando conta do fluxo diário dessa
    | modalidade e o passivo tende a crescer dia após dia em vez de zerar.
    |
    | 3.000 dá folga em relação ao teto de 2.000: uma única execução já
    | consegue reduzir o passivo abaixo disso mesmo que não zere. Ficar
    | consistentemente acima ao longo de vários dias é o sinal real de
    | alarme (isso aqui reporta o instantâneo, não a tendência).
    |
    */
    'pending_documents_alert_threshold' => (int) env('PNCP_PENDING_DOCS_ALERT_THRESHOLD', 3000),

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
