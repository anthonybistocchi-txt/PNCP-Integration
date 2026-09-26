<?php

namespace App\Repositories\Orgaos;

use App\DTOs\Orgaos\ListOrgaosDTO;
use App\DTOs\Paginate\PaginatedResultDTO;
use App\Models\Orgaos\Orgao;
use App\Support\Pagination\Keyset;
use App\Support\Pagination\KeysetPaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class OrgaoRepository
{
    public function __construct(
        private KeysetPaginator $paginator,
    ) {}

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
     * razaoSocial/poderId/esferaId vivem só no payload de public_contratations;
     * a tabela não tem colunas próprias para eles.
     */
    public function distinctOrgaosFromContratacoes(): Collection
    {
        return DB::table('public_contratations')
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

    /**
     * Listagem paginada (cursor keyset por cnpj ASC) dos órgãos ativos.
     */
    public function listPaginated(ListOrgaosDTO $dto): PaginatedResultDTO
    {
        $query = Orgao::query()->where('ativo', true);

        return $this->paginator->paginate($query, $dto->cursor, $dto->limit, Keyset::orgao());
    }

    /**
     * Órgão com todos os seus planos (e itens) de PCA, ordenados.
     *
     * @throws ModelNotFoundException
     */
    public function findWithPcaData(string $cnpj): Orgao
    {
        return Orgao::query()
            ->with([
                'plans' => fn ($query) => $query->orderByDesc('ano_pca')->orderBy('sequencial_pca'),
                'plans.items' => fn ($query) => $query->orderBy('numero_item'),
            ])
            ->where('cnpj', $cnpj)
            ->firstOrFail();
    }
}
