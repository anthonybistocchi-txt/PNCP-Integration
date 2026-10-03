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
     * Órgãos que o sync de PCA deve consultar neste ano.
     *
     * Exclui os já verificados como "sem PCA" neste ano há menos de
     * `pncp.pca.recheck_empty_days`: eram 74% dos órgãos ativos e cada um
     * custava uma requisição por rodada para receber 404.
     *
     * @param  bool  $incluirSemPca  Ignora a memória de "sem PCA" (--force-empty / --cnpj).
     */
    public function listForPcaSync(int $ano, ?string $cnpj = null, bool $incluirSemPca = false): Collection
    {
        $limite = now()->subDays((int) config('pncp.pca.recheck_empty_days', 30));

        return Orgao::query()
            ->where('ativo', true)
            ->when($cnpj, fn ($query, $cnpj) => $query->where('cnpj', $cnpj))
            ->unless($incluirSemPca, function ($query) use ($ano, $limite)
            {
                // Pula só quem está marcado "sem plano" NESTE ano e verificado
                // recentemente. Qualquer outra combinação (nulo, com plano,
                // outro ano, verificação antiga) é consultada.
                $query->where(function ($w) use ($ano, $limite)
                {
                    $w->whereNull('pca_tem_plano')
                        ->orWhere('pca_tem_plano', true)
                        ->orWhereNull('pca_ano_verificado')
                        ->orWhere('pca_ano_verificado', '!=', $ano)
                        ->orWhereNull('pca_verificado_em')
                        ->orWhere('pca_verificado_em', '<=', $limite);
                });
            })
            ->get();
    }

    /**
     * Órgãos que já têm algum plano no ano (os únicos com unidades a descobrir).
     */
    public function listWithPlans(int $ano, ?string $cnpj = null): Collection
    {
        return Orgao::query()
            ->where('ativo', true)
            ->when($cnpj, fn ($query, $cnpj) => $query->where('cnpj', $cnpj))
            ->whereHas('plans', fn ($query) => $query->where('ano_pca', $ano))
            ->get();
    }

    /**
     * Registra o resultado da consulta de PCA do órgão no ano.
     */
    public function markPcaVerified(string $cnpj, int $ano, bool $temPlano): void
    {
        Orgao::query()->where('cnpj', $cnpj)->update([
            'pca_tem_plano'      => $temPlano,
            'pca_ano_verificado' => $ano,
            'pca_verificado_em'  => now(),
        ]);
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
