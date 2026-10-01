<?php

namespace App\Console\Commands;

use App\Repositories\Orgaos\OrgaoRepository;
use Illuminate\Console\Command;

class ImportPncpOrgaosCommand extends Command
{
    private const BATCH_SIZE = 500;

    protected $signature = 'pncp:import-orgaos {--dry-run : Exibe quantos CNPJs seriam importados sem persistir}';

    protected $description = 'Importa CNPJs únicos da tabela de contratações para a tabela orgaos, enriquecendo razao_social/poder/esfera do payload';

    public function handle(OrgaoRepository $orgaoRepository): int
    {
        $this->info('Coletando CNPJs únicos de contratacoes (com metadados do orgaoEntidade)...');

        $orgaos = $orgaoRepository->distinctOrgaosFromContratacoes();

        $this->info("Encontrados {$orgaos->count()} CNPJs únicos.");

        if ($this->option('dry-run'))
        {
            $this->info('Dry-run finalizado.');

            return self::SUCCESS;
        }

        $manuais    = $orgaoRepository->manualCnpjs();
        $existentes = $orgaoRepository->autoImportedCnpjs();

        $now = now();
        $novos = 0;
        $atualizados = 0;
        $ignorados = 0;
        $batch = [];

        foreach ($orgaos as $orgao)
        {
            if (isset($manuais[$orgao->cnpj]))
            {
                $ignorados++;

                continue;
            }

            if (isset($existentes[$orgao->cnpj]))
            {
                $atualizados++;
            }
            else
            {
                $novos++;
            }

            $batch[] = [
                'cnpj'         => $orgao->cnpj,
                'razao_social' => $orgao->razao_social,
                'poder'        => $orgao->poder,
                'esfera'       => $orgao->esfera,
                'ativo'        => true,
                'importado_de' => 'contratacoes',
                'created_at'   => $now,
                'updated_at'   => $now,
            ];

            if (count($batch) >= self::BATCH_SIZE)
            {
                $orgaoRepository->upsertMany($batch);
                $batch = [];
            }
        }

        if ($batch !== [])
        {
            $orgaoRepository->upsertMany($batch);
        }

        $this->info("Importação concluída. Novos: {$novos}, Atualizados: {$atualizados}, Manuais ignorados: {$ignorados}.");

        return self::SUCCESS;
    }
}
