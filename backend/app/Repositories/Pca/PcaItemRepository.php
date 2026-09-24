<?php

namespace App\Repositories\Pca;

use App\DTOs\Paginate\PaginatedResultDTO;
use App\DTOs\Pca\CursorDTO;
use App\DTOs\Pca\ListPcaItemsDTO;
use App\Models\Pca\PcaItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PcaItemRepository
{
    /**
     * Consulta os itens do PCA no banco local usando os filtros do DTO.
     *
     * Usa Keyset Pagination (cursor-based) em vez de OFFSET, com ordenação
     * estável em (data_publicacao_pncp DESC, id ASC).
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
            ->join('pca_plans', 'pca_items.pca_plan_id', '=', 'pca_plans.id');

        if ($dto->ano) {
            $query->where('pca_plans.ano_pca', $dto->ano);
        }

        if ($dto->cnpj) {
            $query->where('pca_plans.cnpj_orgao', $dto->cnpj);
        }

        if ($dto->categoriaItem) {
            $query->where('pca_items.categoria_item_pca', $dto->categoriaItem);
        }

        if ($dto->search) {
            $search = DB::getPdo()->quote($dto->search);
            $query->whereRaw("pca_items.search_vector @@ websearch_to_tsquery('portuguese', {$search})");
            // Pontuação para ordenar pelos resultados mais relevantes.
            $query->selectRaw("ts_rank(pca_items.search_vector, websearch_to_tsquery('portuguese', {$search})) AS rank");
            $query->orderByDesc('rank');
        }

        return $this->paginateByCursor($query, $dto->cursor, $dto->limit);
    }

    /**
     * Aplica keyset pagination (cursor-based) na query já filtrada e devolve
     * o resultado paginado com as contagens do ciclo.
     */
    private function paginateByCursor(Builder $query, ?string $cursor, int $limit): PaginatedResultDTO
    {
        $cursorData = $this->decodeCursor($cursor);

        // Clonamos a base ANTES de orderBy/limit/cursor para contar o total real.
        $baseQuery = clone $query;

        $totalCount = $baseQuery->count();
        $currentPage = 1;

        if ($cursorData) {
            // Quantos registros ficaram para trás (antes do cursor) para
            // descobrir em qual "página" estamos.
            $skippedQuery = clone $baseQuery;
            $skippedCount = $skippedQuery->where(function ($query) use ($cursorData) {
                $query->where('pca_items.data_publicacao_pncp', '>', $cursorData->date)
                    ->orWhere(function ($query) use ($cursorData) {
                        $query->where('pca_items.data_publicacao_pncp', '=', $cursorData->date)
                            ->where('pca_items.id', '<=', $cursorData->id);
                    });
            })->count();

            $currentPage = (int) floor($skippedCount / $limit) + 1;

            $query->where(function ($query) use ($cursorData) {
                $query->where('pca_items.data_publicacao_pncp', '<', $cursorData->date)
                    ->orWhere(function ($query) use ($cursorData) {
                        $query->where('pca_items.data_publicacao_pncp', '=', $cursorData->date)
                            ->where('pca_items.id', '>', $cursorData->id);
                    });
            });
        }

        $itemsPage = $query
            ->orderByDesc('pca_items.data_publicacao_pncp')
            ->orderBy('pca_items.id')
            ->limit($limit)
            ->get();

        $nextCursor = $this->buildNextCursor($itemsPage, $limit);

        return new PaginatedResultDTO(
            data:        $itemsPage,
            page:        $currentPage,
            count_page:  $itemsPage->count(),
            count:       $totalCount,
            next_cursor: $nextCursor,
            has_more:    $nextCursor !== null,
        );
    }

    /**
     * Gera o cursor da próxima página a partir do último item retornado.
     * Retorna null quando não há mais páginas (resultado menor que o limit).
     */
    private function buildNextCursor(Collection $itemsPage, int $limit): ?string
    {
        if ($itemsPage->count() < $limit) {
            return null;
        }

        $lastItem = $itemsPage->last();
        $publishedAt = $lastItem->data_publicacao_pncp;

        return $this->encodeCursor(
            date: $publishedAt instanceof \DateTimeInterface
                ? $publishedAt->format('Y-m-d')
                : (string) $publishedAt,
            id: (int) $lastItem->id,
        );
    }

    /**
     * Decodifica o cursor recebido do cliente.
     * Retorna null se o cursor for inválido ou ausente (primeira página).
     */
    private function decodeCursor(?string $cursor): ?CursorDTO
    {
        if (!$cursor) {
            return null;
        }

        $decodedCursor = json_decode($this->decodeCursorPayload($cursor), true);

        if (!is_array($decodedCursor) || empty($decodedCursor['date']) || !isset($decodedCursor['id'])) {
            return null;
        }

        return new CursorDTO(
            date: (string) $decodedCursor['date'],
            id:   (int) $decodedCursor['id'],
        );
    }

    private function encodeCursor(string $date, int $id): string
    {
        $payload = json_encode([
            'date' => $date,
            'id'   => $id,
        ], JSON_UNESCAPED_SLASHES);

        return rtrim(strtr(base64_encode((string) $payload), '+/', '-_'), '=');
    }

    private function decodeCursorPayload(string $cursor): string
    {
        $normalized = strtr(str_replace(' ', '+', $cursor), '-_', '+/');
        $padding = strlen($normalized) % 4;

        if ($padding > 0) {
            $normalized .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($normalized, true);

        return $decoded !== false ? $decoded : (string) base64_decode($cursor);
    }
}
