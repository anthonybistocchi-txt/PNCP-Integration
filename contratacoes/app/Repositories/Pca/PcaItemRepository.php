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
                'itens_pca.*',
                'planos_pca.cnpj_orgao',
                'planos_pca.ano_pca',
                'planos_pca.codigo_unidade',
                'planos_pca.nome_unidade',
            ])
            ->join('planos_pca', 'itens_pca.plano_pca_id', '=', 'planos_pca.id')
            ->when($dto->ano, function (Builder $query) use ($dto) {
                $query->where('planos_pca.ano_pca', $dto->ano);
            })
            ->when($dto->cnpj, function (Builder $query) use ($dto) {
                $query->where('planos_pca.cnpj_orgao', $dto->cnpj);
            })
            ->when($dto->categoriaItem, function (Builder $query) use ($dto) {
                $query->where('itens_pca.categoria_item_pca', $dto->categoriaItem);
            })
            ->when($dto->search, function (Builder $query) use ($dto) {
                $search = DB::getPdo()->quote($dto->search);
                $query->whereRaw("itens_pca.search_vector @@ websearch_to_tsquery('portuguese', {$search})");
            });

        return $this->paginator->paginate($query, $dto->cursor, $dto->limit, Keyset::pca());
    }
}
