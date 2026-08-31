<?php

namespace App\Console\Commands;

use App\DTOs\PublicContratations\ListPublicContratationsDTO;
use App\Jobs\PublicContratations\SyncPncpDataJob;
use Illuminate\Console\Command;

class SyncPncpCommand extends Command
{
    /**
     * --days=1 (padrão): ontem até hoje, para o cron diário.
     * --days=30: carga inicial / backfill.
     * --modalidades=1,2,7: restringe o disparo. Sem a flag, usa todas (1–10).
     */
    protected $signature = 'pncp:sync
        {--days=1 : Quantidade de dias para trás (1 = ontem até hoje)}
        {--modalidades= : Lista separada por vírgula. Vazio = todas}';

    protected $description = 'Dispara o Job de sincronização com o PNCP para alimentar o banco de dados local.';

    private array $todasModalidades = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];

    public function handle(): int
    {
        $days        = max(1, (int) $this->option('days'));
        $modalidades = $this->resolverModalidades();

        if ($modalidades === []) {
            $this->error('Nenhuma modalidade válida. Use IDs de 1 a 10, separados por vírgula.');
            return self::FAILURE;
        }

        $dataInicial = now()->subDays($days)->format('Ymd');
        $dataFinal   = now()->format('Ymd');

        $this->info("Sincronizando PNCP de {$dataInicial} até {$dataFinal} ({$days} dia(s)). Modalidades: " . implode(', ', $modalidades));

        foreach ($modalidades as $modalidade) {
            $dto = new ListPublicContratationsDTO(
                dataInicial: $dataInicial,
                dataFinal: $dataFinal,
                codigoModalidadeContratacao: $modalidade,
                uf: null,
                codigoMunicipioIbge: null,
                cursor: null,
                limit: 50,
            );

            SyncPncpDataJob::dispatch($dto);

            $this->info("Job enviado: modalidade {$modalidade}, {$dataInicial} até {$dataFinal}.");
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
