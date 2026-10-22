<?php

namespace App\Console\Commands;

use App\DTOs\Contratacoes\ListContratacoesDTO;
use App\Integrations\PNCP\ContratacoesCobertura;
use App\Jobs\Contratacoes\SyncContratacoesJob;
use Illuminate\Console\Command;

class SyncContratacoesCommand extends Command
{
    /**
     * --days=1 (padrão): ontem até hoje, para o cron diário.
     * --days=30: carga inicial / backfill relativo.
     * --data-inicial / --data-final (Ymd): intervalo absoluto; se presentes,
     * ignoram --days. Necessário para reprocessar um gap depois da meia-noite.
     * --modalidades=1,2,7: restringe o disparo. Sem a flag, usa todas (1–10).
     */
    protected $signature = 'pncp:sync-contratacoes
        {--days=1 : Quantidade de dias para trás (1 = ontem até hoje); ignorado se --data-inicial/--data-final forem passados}
        {--data-inicial= : Data inicial absoluta (Ymd). Exige --data-final}
        {--data-final= : Data final absoluta (Ymd). Exige --data-inicial}
        {--modalidades= : Lista separada por vírgula. Vazio = todas}';

    protected $description = 'Dispara o Job de sincronização com o PNCP para alimentar o banco de dados local.';

    private array $todasModalidades = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];

    public function handle(ContratacoesCobertura $cobertura): int
    {
        $modalidades = $this->resolverModalidades();

        if ($modalidades === [])
        {
            $this->error('Nenhuma modalidade válida. Use IDs de 1 a 10, separados por vírgula.');
            return self::FAILURE;
        }

        $enviados = [];

        if ($this->temDatasAbsolutas() || (int) $this->option('days') !== 1)
        {
            $intervalo = $this->resolverIntervalo();

            if ($intervalo === null)
            {
                return self::FAILURE;
            }

            [$dataInicial, $dataFinal] = $intervalo;

            $this->info("Sincronizando PNCP de {$dataInicial} até {$dataFinal}. Modalidades: " . implode(', ', $modalidades));

            foreach ($modalidades as $modalidade)
            {
                $this->despachar($modalidade, $dataInicial, $dataFinal, $enviados);
            }
        }

        foreach ($cobertura->intervalosEmAberto($modalidades) as $intervalo)
        {
            $this->despachar(
                $intervalo['modalidade'],
                $intervalo['data_inicial'],
                $intervalo['data_final'],
                $enviados
            );
        }

        if ($enviados === [])
        {
            $this->info('Nenhum intervalo de contratação pendente.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, true>  $enviados
     */
    private function despachar(int $modalidade, string $dataInicial, string $dataFinal, array &$enviados): void
    {
        $chave = $modalidade . ':' . $dataInicial . ':' . $dataFinal;

        if (isset($enviados[$chave]))
        {
            return;
        }

        $enviados[$chave] = true;

        SyncContratacoesJob::dispatchFor(new ListContratacoesDTO(
            dataInicial:                 $dataInicial,
            dataFinal:                   $dataFinal,
            codigoModalidadeContratacao: $modalidade,
            uf:                          null,
            codigoMunicipioIbge:         null,
            cursor:                      null,
            limit:                       50,
        ));

        $this->info("Job enviado: modalidade {$modalidade}, {$dataInicial} até {$dataFinal}.");
    }

    private function temDatasAbsolutas(): bool
    {
        return trim((string) $this->option('data-inicial')) !== ''
            || trim((string) $this->option('data-final')) !== '';
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function resolverIntervalo(): ?array
    {
        $dataInicial = trim((string) $this->option('data-inicial'));
        $dataFinal   = trim((string) $this->option('data-final'));

        if ($dataInicial === '' && $dataFinal === '')
        {
            $days = max(1, (int) $this->option('days'));

            return [
                now()->subDays($days)->format('Ymd'),
                now()->format('Ymd'),
            ];
        }

        if ($dataInicial === '' || $dataFinal === '')
        {
            $this->error('Use --data-inicial e --data-final juntos (formato Ymd), ou só --days.');

            return null;
        }

        if (! preg_match('/^\d{8}$/', $dataInicial) || ! preg_match('/^\d{8}$/', $dataFinal))
        {
            $this->error('Datas devem estar no formato Ymd (ex.: 20260914).');

            return null;
        }

        if ($dataInicial > $dataFinal)
        {
            $this->error('data-inicial não pode ser posterior a data-final.');

            return null;
        }

        return [$dataInicial, $dataFinal];
    }

    /**
     * @return list<int>
     */
    private function resolverModalidades(): array
    {
        $raw = trim((string) $this->option('modalidades'));

        if ($raw === '')
        {
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
