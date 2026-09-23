<?php

namespace App\Console\Commands;

use App\Models\Pncp\PncpOrgao;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportPncpOrgaosCommand extends Command
{
    protected $signature = 'pncp:import-orgaos {--dry-run : Exibe quantos CNPJs seriam importados sem persistir}';

    protected $description = 'Importa CNPJs únicos de public_contratations para pncp_orgaos, enriquecendo razao_social/poder/esfera do payload';

    public function handle(): int
    {
        $this->info('Coletando CNPJs únicos de public_contratations (com metadados do orgaoEntidade)...');

        // Um registro por CNPJ: o mais recente por data_atualizacao_global.
        // razaoSocial/poderId/esferaId vivem só no payload — public_contratations
        // não tem colunas próprias para isso.
        $orgaos = DB::table('public_contratations')
            ->selectRaw("
                DISTINCT ON (orgao_cnpj)
                orgao_cnpj AS cnpj,
                payload_completo->'orgaoEntidade'->>'razaoSocial' AS razao_social,
                payload_completo->'orgaoEntidade'->>'poderId' AS poder,
                payload_completo->'orgaoEntidade'->>'esferaId' AS esfera
            ")
            ->whereNotNull('orgao_cnpj')
            ->orderBy('orgao_cnpj')
            ->orderByRaw('data_atualizacao_global DESC NULLS LAST')
            ->get();

        $this->info("Encontrados {$orgaos->count()} CNPJs únicos.");

        if ($this->option('dry-run')) {
            $this->info('Dry-run finalizado.');

            return self::SUCCESS;
        }

        $manuais = PncpOrgao::query()
            ->where('importado_de', 'manual')
            ->pluck('cnpj')
            ->flip();

        $now = now();
        $novos = 0;
        $atualizados = 0;
        $ignorados = 0;
        $batch = [];

        $existentes = PncpOrgao::query()
            ->where('importado_de', '!=', 'manual')
            ->pluck('cnpj')
            ->flip();

        foreach ($orgaos as $orgao) {
            if (isset($manuais[$orgao->cnpj])) {
                $ignorados++;

                continue;
            }

            if (isset($existentes[$orgao->cnpj])) {
                $atualizados++;
            } else {
                $novos++;
            }

            $batch[] = [
                'cnpj' => $orgao->cnpj,
                'razao_social' => $orgao->razao_social,
                'poder' => $orgao->poder,
                'esfera' => $orgao->esfera,
                'ativo' => true,
                'importado_de' => 'public_contratations',
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($batch) >= 500) {
                $this->flushBatch($batch);
                $batch = [];
            }
        }

        if ($batch !== []) {
            $this->flushBatch($batch);
        }

        $this->info("Importação concluída. Novos: {$novos}, Atualizados: {$atualizados}, Manuais ignorados: {$ignorados}.");

        return self::SUCCESS;
    }

    /**
     * Insert ou update. Não toca em `ativo` nem `importado_de` no conflito —
     * só enriquece metadados. Manuais já foram filtrados do batch.
     *
     * @param  list<array<string, mixed>>  $batch
     */
    private function flushBatch(array $batch): void
    {
        PncpOrgao::upsert(
            $batch,
            ['cnpj'],
            ['razao_social', 'poder', 'esfera', 'updated_at']
        );
    }
}
