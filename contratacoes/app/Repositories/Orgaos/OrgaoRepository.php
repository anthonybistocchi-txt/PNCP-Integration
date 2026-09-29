<?php

namespace App\Repositories\Orgaos;

use App\DTOs\Orgaos\ListOrgaosDTO;
use App\DTOs\Paginate\PaginatedResultDTO;
use App\Models\Orgaos\Orgao;
use App\Support\Pagination\Keyset;
use App\Support\Pagination\KeysetPaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class OrgaoRepository
{
    public function __construct(
        private KeysetPaginator $paginator,
    ) {}

    /**
     * Lista paginada (cursor keyset por cnpj ASC) dos órgãos ativos.
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
                'plans.itens' => fn ($query) => $query->orderBy('numero_item'),
            ])
            ->where('cnpj', $cnpj)
            ->firstOrFail();
    }
}
