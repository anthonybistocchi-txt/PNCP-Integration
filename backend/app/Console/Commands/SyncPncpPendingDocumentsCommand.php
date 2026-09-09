<?php

namespace App\Console\Commands;

use App\Jobs\PublicContratations\SyncPncpPendingDocumentsJob;
use Illuminate\Console\Command;

/**
 * Varredura independente do cron diário: redespacha a busca de documentos
 * para qualquer licitação com `documents_fetched_at IS NULL`, sem olhar
 * apenas para o intervalo de datas do ciclo de listagem que a originou.
 *
 * Ver SyncPncpPendingDocumentsJob para o porquê disso ser necessário.
 */
class SyncPncpPendingDocumentsCommand extends Command
{
    protected $signature = 'pncp:sync-documentos-pendentes
        {--modalidades= : Lista separada por vírgula. Vazio = todas}';

    protected $description = 'Redespacha a busca de documentos pendentes (documents_fetched_at nulo), de qualquer data.';

    private array $todasModalidades = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];

    public function handle(): int
    {
        $modalidades = $this->resolverModalidades();

        if ($modalidades === []) {
            $this->error('Nenhuma modalidade válida. Use IDs de 1 a 10, separados por vírgula.');
            return self::FAILURE;
        }

        $this->info('Varredura de documentos pendentes. Modalidades: ' . implode(', ', $modalidades));

        // Escalona o disparo entre modalidades para não empilhar N jobs
        // mestres buscando/despachando lotes ao mesmo tempo. Independente do
        // PncpDocumentsDispatchSequencer usado pelo fluxo automático diário:
        // esta varredura roda em horários próprios (fora do pico das 04:30),
        // então não precisa competir pelo mesmo contador do dia.
        $staggerMinutes = max(0, (int) config('pncp.documents_stagger_minutes', 8));

        foreach (array_values($modalidades) as $indice => $modalidade) {
            $delayMinutes = $indice * $staggerMinutes;

            SyncPncpPendingDocumentsJob::dispatch($modalidade)
                ->delay(now()->addMinutes($delayMinutes));

            $this->info("Job enviado: modalidade {$modalidade}, delay de {$delayMinutes}min.");
        }

        return self::SUCCESS;
    }

    /**
     * @return list<int>
     */
    private function resolverModalidades(): array
    {
        $raw = trim((string) $this->option('modalidades'));

        if ($raw === '') {
            return $this->todasModalidades;
        }

        $ids = array_map('intval', explode(',', $raw));
        $ids = array_values(array_unique(array_filter(
            $ids,
            fn (int $id) => in_array($id, $this->todasModalidades, true)
        )));

        sort($ids);

        return $ids;
    }
}
