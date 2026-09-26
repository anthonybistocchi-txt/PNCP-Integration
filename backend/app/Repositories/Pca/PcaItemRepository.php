<?php

namespace App\Repositories\Pca;

use App\DTOs\Paginate\PaginatedResultDTO;
use App\DTOs\Pca\ListPcaItemsDTO;
use App\Models\Pca\PcaItem;
use App\Support\Pagination\Keyset;
use App\Support\Pagination\KeysetPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class PcaItemRepository
{
    public function __construct(
        private KeysetPaginator $paginator,
    ) {}

    /**
     * Consulta os itens do PCA no banco local usando os filtros do DTO.
     *
     * Usa Keyset Pagination (cursor-based) em vez de OFFSET, com ordenação
     * estável em (data_publicacao_pncp DESC, id ASC).
     *
     * Quando `search` é fornecido, o FTS filtra os itens relevantes mas a
     * ordenação permanece (data_publicacao_pncp DESC, id ASC) — estável para
     * o cursor, sem pular/repetir itens entre páginas.
     *
     * Formato do cursor: Base64 URL-safe de JSON({date: Y-m-d, id}).
     */
    public function list(ListPcaItemsDTO $dto): PaginatedResultDTO
    {
        $query = PcaItem::query()
            ->select([
                'pca_items.*',
                'pca_plans.cnpj_orgao',
                'pca_plans.ano_pca',
                'pca_plans.codigo_unidade',
                'pca_plans.nome_unidade',
            ])
            ->join('pca_plans', 'pca_items.pca_plan_id', '=', 'pca_plans.id')
            ->when($dto->ano, function (Builder $query) use ($dto) {
                $query->where('pca_plans.ano_pca', $dto->ano);
            })
            ->when($dto->cnpj, function (Builder $query) use ($dto) {
                $query->where('pca_plans.cnpj_orgao', $dto->cnpj);
            })
            ->when($dto->categoriaItem, function (Builder $query) use ($dto) {
                $query->where('pca_items.categoria_item_pca', $dto->categoriaItem);
            })
            ->when($dto->search, function (Builder $query) use ($dto) {
                $search = DB::getPdo()->quote($dto->search);
                $query->whereRaw("pca_items.search_vector @@ websearch_to_tsquery('portuguese', {$search})");
            });

        return $this->paginator->paginate($query, $dto->cursor, $dto->limit, Keyset::pca());
    }
}
