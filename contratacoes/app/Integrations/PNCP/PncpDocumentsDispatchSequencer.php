<?php

namespace App\Integrations\PNCP;

use Illuminate\Support\Facades\Cache;

/**
 * Escalona o disparo da Fase 2 (documentos) entre as modalidades que terminam
 * a contratacoes no mesmo dia.
 *
 * Sem isso, modalidades que terminam a Fase 1 quase juntas (o caso comum,
 * já que todas são disparadas no mesmo horário) disparam SyncDocumentosJob
 * quase juntas, gerando um pico concentrado de chamadas a /arquivos — foi
 * exatamente isso que, na madrugada de 05/09, colocou o circuit breaker em
 * nível máximo por quase 6 horas.
 *
 * O contador é diário: cada modalidade que termina o ciclo de contratacoes
 * reserva a próxima posição na fila, e o Job Mestre da Fase 2 correspondente
 * é atrasado proporcionalmente a essa posição.
 */
class PncpDocumentsDispatchSequencer
{
    private const TTL_HOURS = 24;

    /**
     * Reserva a próxima posição do dia e devolve o delay (em minutos) que a
     * Fase 2 dessa modalidade deve esperar antes de ser enfileirada.
     */
    public function nextDelayMinutes(): int
    {
        $key = $this->key();

        // add() só cria a chave se ela não existir, garantindo que o TTL seja
        // definido pela primeira modalidade do dia e nunca renovado depois.
        Cache::add($key, 0, now()->addHours(self::TTL_HOURS));

        $position = (int) Cache::increment($key);
        $stagger  = max(0, (int) config('pncp.documents_stagger_minutes', 8));

        return ($position - 1) * $stagger;
    }

    private function key(): string
    {
        return 'pncp_docs:dispatch_order:' . now()->format('Ymd');
    }
}
