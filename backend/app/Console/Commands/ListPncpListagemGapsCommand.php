<?php

namespace App\Console\Commands;

use App\Models\PublicContratations\PncpListagemCiclo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Lista ciclos de listagem incompletos (auditoria em pncp_listagem_ciclos).
 * Não dispara retry — imprime o pncp:sync sugerido para o operador.
 */
class ListPncpListagemGapsCommand extends Command
{
    protected $signature = 'pncp:listagem-gaps
        {--json : Saída JSON em vez de tabela}
        {--for-schedule : Não imprime no stdout; só loga se houver gap (para o cron)}';

    protected $description = 'Lista ciclos de listagem PNCP incompletos (abandoned, finished abaixo do anunciado, ou started além do retryUntil).';

    public function handle(): int
    {
        $retryHours = (int) config('pncp.retry_until_hours.listagem', 12);

        $ciclos = PncpListagemCiclo::query()
            ->emGap($retryHours)
            ->orderBy('data_inicial')
            ->orderBy('modalidade')
            ->get();

        if ($ciclos->isEmpty()) {
            if (! $this->option('for-schedule')) {
                $this->info('Nenhum gap de listagem.');
            }

            Log::info('PNCP_LISTAGEM_GAPS_OK', [
                'total'                  => 0,
                'listagem_retry_hours'   => $retryHours,
            ]);

            return self::SUCCESS;
        }

        $linhas = $ciclos->map(function (PncpListagemCiclo $ciclo) {
            $faltam = max(0, $ciclo->total_anunciado - $ciclo->items_processados);

            return [
                'id'                => $ciclo->id,
                'sync_id'           => $ciclo->sync_id,
                'modalidade'        => $ciclo->modalidade,
                'data_inicial'      => $ciclo->data_inicial,
                'data_final'        => $ciclo->data_final,
                'status'            => $ciclo->status,
                'total_anunciado'   => $ciclo->total_anunciado,
                'items_processados' => $ciclo->items_processados,
                'faltam'            => $faltam,
                'started_at'        => $ciclo->started_at?->toDateTimeString(),
                'comando'           => sprintf(
                    'php artisan pncp:sync --data-inicial=%s --data-final=%s --modalidades=%d',
                    $ciclo->data_inicial,
                    $ciclo->data_final,
                    $ciclo->modalidade
                ),
            ];
        });

        Log::warning('PNCP_LISTAGEM_GAPS', [
            'total'                => $linhas->count(),
            'listagem_retry_hours' => $retryHours,
            'ciclos'               => $linhas->map(fn (array $l) => [
                'modalidade'        => $l['modalidade'],
                'data_inicial'      => $l['data_inicial'],
                'data_final'        => $l['data_final'],
                'status'            => $l['status'],
                'total_anunciado'   => $l['total_anunciado'],
                'items_processados' => $l['items_processados'],
                'faltam'            => $l['faltam'],
            ])->values()->all(),
            'mensagem'             => 'Há ciclos de listagem incompletos. Reprocesse com pncp:sync e datas absolutas '
                . 'depois que a listagem do dia corrente tiver terminado (evita colidir no uniqueId e no throttle).',
        ]);

        if ($this->option('for-schedule')) {
            return self::SUCCESS;
        }

        if ($this->option('json')) {
            $this->line(json_encode($linhas->values()->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->warn("Gaps de listagem ({$linhas->count()}). Limiar started: {$retryHours}h.");
        $this->table(
            ['Mod', 'Intervalo', 'Status', 'Anunciado', 'Processados', 'Faltam', 'Started at'],
            $linhas->map(fn (array $l) => [
                $l['modalidade'],
                $l['data_inicial'] . '→' . $l['data_final'],
                $l['status'],
                $l['total_anunciado'],
                $l['items_processados'],
                $l['faltam'],
                $l['started_at'] ?? '-',
            ])->all()
        );

        $this->newLine();
        $this->info('Comandos sugeridos (rode fora do pico da listagem do dia):');
        foreach ($linhas as $linha) {
            $this->line('  ' . $linha['comando']);
        }

        $startedAtivos = PncpListagemCiclo::query()
            ->where('status', PncpListagemCiclo::STATUS_STARTED)
            ->where('started_at', '>', now()->subHours($retryHours))
            ->orderBy('modalidade')
            ->get(['modalidade', 'data_inicial', 'data_final', 'started_at']);

        if ($startedAtivos->isNotEmpty()) {
            $this->newLine();
            $this->comment('Ciclos ainda started (dentro de ' . $retryHours . 'h) — um pncp:sync do mesmo '
                . 'intervalo/modalidade seria descartado pelo uniqueId até o lock expirar:');
            foreach ($startedAtivos as $ativo) {
                $this->line(sprintf(
                    '  mod %d %s→%s desde %s',
                    $ativo->modalidade,
                    $ativo->data_inicial,
                    $ativo->data_final,
                    $ativo->started_at?->toDateTimeString() ?? '-'
                ));
            }
        }

        return self::SUCCESS;
    }
}
