<?php

namespace App\Console\Commands;

use App\DTOs\Contratacoes\ListContratacoesDTO;
use App\Integrations\PNCP\ContratacoesCobertura;
use App\Jobs\Contratacoes\SyncContratacoesJob;
use App\Repositories\Contratacoes\CicloContratacaoRepository;
use Illuminate\Console\Command;

/**
 * Reprocessa gaps de contratacoes (ciclos_contratacao): abandoned, finished abaixo
 * do anunciado, ou started além do retryUntil. Complementa o termômetro
 * ciclos_contratacao disparando SyncContratacoesJob por intervalo/modalidade.
 */
class SyncContratacoesFaltantesCommand extends Command
{
    protected $signature = 'pncp:sync-contratacoes-faltantes';

    protected $description = 'Reprocessa ciclos de contratacoes PNCP incompletos (gaps em ciclos_contratacao).';

    private array $modalidades = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];

    public function handle(CicloContratacaoRepository $cicloRepository, ContratacoesCobertura $cobertura): int
    {
        $retryHours = (int) config('pncp.retry_until_hours.contratacoes', 12);
        $enviados   = [];

        foreach ($cobertura->intervalosEmAberto($this->modalidades) as $intervalo)
        {
            $this->despachar(
                $intervalo['modalidade'],
                $intervalo['data_inicial'],
                $intervalo['data_final'],
                $enviados
            );
        }

        foreach ($cobertura->intervalosParaRechecagem($this->modalidades) as $intervalo)
        {
            $this->despachar(
                $intervalo['modalidade'],
                $intervalo['data_inicial'],
                $intervalo['data_final'],
                $enviados
            );
        }

        foreach ($cicloRepository->intervalosIncompletos($retryHours) as $ciclo)
        {
            $modalidade = (int) $ciclo->modalidade;

            if ($cobertura->temSucesso($modalidade, $ciclo->data_inicial, $ciclo->data_final))
            {
                continue;
            }

            $this->despachar($modalidade, $ciclo->data_inicial, $ciclo->data_final, $enviados);
        }

        if ($enviados === [])
        {
            $this->info('Nenhum intervalo de contratação pendente.');

            return self::SUCCESS;
        }

        $this->info('Reprocessando ' . count($enviados) . ' intervalo(s).');

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
            dataInicial: $dataInicial,
            dataFinal: $dataFinal,
            codigoModalidadeContratacao: $modalidade,
            uf: null,
            codigoMunicipioIbge: null,
            cursor: null,
            limit: 50,
        ));

        $this->info(sprintf(
            'Job enviado: modalidade %d, %s até %s.',
            $modalidade,
            $dataInicial,
            $dataFinal
        ));
    }
}
