<?php

namespace App\Repositories\Orgaos;

use App\Models\Orgaos\Orgao;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class OrgaoRepository
{
    public function listActive(?string $cnpj = null): Collection
    {
        return Orgao::query()
            ->where('ativo', true)
            ->when($cnpj, fn ($query, $cnpj) => $query->where('cnpj', $cnpj))
            ->get();
    }

    /**
     * Um registro por CNPJ — o mais recente por data_atualizacao_global.
     *
     * razaoSocial/poderId/esferaId vivem só no payload de contratacoes.
     */
    public function distinctOrgaosFromContratacoes(): Collection
    {
        return DB::table('contratacoes')
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
    }

    /**
     * CNPJs importados manualmente: nunca sobrescritos pela importação.
     */
    public function manualCnpjs(): Collection
    {
        return Orgao::query()
            ->where('importado_de', 'manual')
            ->pluck('cnpj')
            ->flip();
    }

    /**
     * CNPJs importados automaticamente: podem ser enriquecidos.
     */
    public function autoImportedCnpjs(): Collection
    {
        return Orgao::query()
            ->where('importado_de', '!=', 'manual')
            ->pluck('cnpj')
            ->flip();
    }

    /**
     * Insert/update em lote sem tocar em `ativo` nem `importado_de`.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function upsertMany(array $rows): void
    {
        Orgao::upsert(
            $rows,
            ['cnpj'],
            ['razao_social', 'poder', 'esfera', 'updated_at']
        );
    }
}
