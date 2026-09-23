<?php

namespace App\Repositories\Pca;

use App\DTOs\Pca\ListPcaItemsDTO;
use App\Models\Pca\PcaItem;
use Illuminate\Support\Facades\DB;

class PcaItemRepository
{
    public function list(ListPcaItemsDTO $dto): array
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
            // Adiciona pontuação para ordenar pelos resultados mais relevantes
            $query->selectRaw("ts_rank(pca_items.search_vector, websearch_to_tsquery('portuguese', {$search})) AS rank");
            $query->orderByDesc('rank');
        }

        // Keyset Pagination Decoder
        if ($dto->cursor) {
            $decoded = json_decode(base64_decode($dto->cursor), true);
            if ($decoded && isset($decoded['date']) && isset($decoded['id'])) {
                $query->where(function ($q) use ($decoded) {
                    $q->where('pca_items.data_publicacao_pncp', '<', $decoded['date'])
                      ->orWhere(function ($q2) use ($decoded) {
                          $q2->where('pca_items.data_publicacao_pncp', '=', $decoded['date'])
                             ->where('pca_items.id', '>', $decoded['id']);
                      });
                });
            }
        }

        // Default Ordering para Keyset
        $query->orderByDesc('pca_items.data_publicacao_pncp')
              ->orderBy('pca_items.id');

        $query->limit($dto->limit + 1); // +1 para saber se tem próxima página

        $results = $query->get();

        $hasMore = $results->count() > $dto->limit;
        if ($hasMore) {
            $results->pop();
        }

        $nextCursor = null;
        if ($hasMore && $results->isNotEmpty()) {
            $lastItem = $results->last();
            $nextCursor = base64_encode(json_encode([
                'date' => $lastItem->data_publicacao_pncp ? $lastItem->data_publicacao_pncp->toDateString() : null,
                'id' => $lastItem->id
            ]));
        }

        return [
            'data' => $results,
            'next_cursor' => $nextCursor,
        ];
    }
}
