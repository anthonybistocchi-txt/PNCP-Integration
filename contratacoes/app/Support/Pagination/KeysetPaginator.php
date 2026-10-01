<?php

namespace App\Support\Pagination;

use App\DTOs\Paginate\PaginatedResultDTO;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Paginador por cursor keyset reutilizável.
 *
 * Toda a lógica de encode/decode de cursor, predicados "before"/"after",
 * contagem de página e montagem do PaginatedResultDTO vive aqui —
 * sem duplicação entre os repositórios.
 *
 * Uso:
 *   $this->paginator->paginate($query, $dto->cursor, $dto->limit, Keyset::pca());
 */
class KeysetPaginator
{
    /**
     * Aplica paginação keyset na query já filtrada e retorna o resultado paginado.
     *
     * @param  Builder  $query  Query base (já com selects e wheres de filtro).
     * @param  string|null  $cursor  Cursor opaco recebido do cliente (base64url JSON).
     * @param  int  $limit  Número máximo de itens por página.
     * @param  Keyset  $k  Descritor da ordenação keyset.
     */
    public function paginate(Builder $query, ?string $cursor, int $limit, Keyset $k): PaginatedResultDTO
    {
        $cursorData = $this->decodeToArray($cursor, $k);

        // Clona a query base ANTES de orderBy / limit / predicado de cursor
        // para obter o total real que bate com os filtros principais.
        $baseQuery = clone $query;
        $totalCount = $baseQuery->count();
        $currentPage = 1;

        if ($cursorData !== null)
        {
            $primaryValue = $cursorData['primary'];
            $tieValue = $cursorData['tie'] ?? null;

            // Conta os registros que ficaram antes do cursor para calcular a página.
            $skippedCount = (clone $baseQuery)
                ->where(fn ($q) => $this->beforePredicate($q, $k, $primaryValue, $tieValue))
                ->count();

            $currentPage = (int) floor($skippedCount / $limit) + 1;

            // Aplica o predicado "after" para buscar apenas os itens após o cursor.
            $query->where(fn ($q) => $this->afterPredicate($q, $k, $primaryValue, $tieValue));
        }

        $tieDirection = 'asc'; // o desempate é sempre ASC (crescente por ID ou controle)

        $itemsPage = $query
            ->orderBy($k->primaryColumn, $k->direction)
            ->when($k->tieColumn !== null, fn ($q) => $q->orderBy($k->tieColumn, $tieDirection))
            ->limit($limit)
            ->get();

        $nextCursor = $this->buildNextCursor($itemsPage, $limit, $k);

        return new PaginatedResultDTO(
            data:        $itemsPage,
            page:        $currentPage,
            count_page:  $itemsPage->count(),
            count:       $totalCount,
            next_cursor: $nextCursor,
            has_more:    $nextCursor !== null,
        );
    }

    // -------------------------------------------------------------------------
    // Predicados SQL
    // -------------------------------------------------------------------------

    /**
     * Registros "before" do cursor = os que ficaram para trás na ordenação.
     * Usados apenas para calcular a página atual (skipped count).
     *
     * DESC: before = primary > v  OR  (primary = v AND tie <= t)
     * ASC:  before = primary < v  OR  (primary = v AND tie <= t)
     */
    private function beforePredicate(Builder $q, Keyset $k, mixed $primaryValue, mixed $tieValue): void
    {
        $gtOp = $k->direction === 'desc' ? '>' : '<';

        $q->where($k->primaryColumn, $gtOp, $primaryValue)
            ->when($k->tieColumn !== null && $tieValue !== null, function ($q) use ($k, $primaryValue, $tieValue)
            {
                $q->orWhere(function ($q) use ($k, $primaryValue, $tieValue)
                {
                    $q->where($k->primaryColumn, '=', $primaryValue)
                        ->where($k->tieColumn, '<=', $tieValue);
                });
            });
    }

    /**
     * Registros "after" do cursor = os que ainda faltam.
     * Aplicados na query principal para buscar a próxima página.
     *
     * DESC: after = primary < v  OR  (primary = v AND tie > t)
     * ASC:  after = primary > v  OR  (primary = v AND tie > t)
     */
    private function afterPredicate(Builder $q, Keyset $k, mixed $primaryValue, mixed $tieValue): void
    {
        $ltOp = $k->direction === 'desc' ? '<' : '>';

        $q->where($k->primaryColumn, $ltOp, $primaryValue)
            ->when($k->tieColumn !== null && $tieValue !== null, function ($q) use ($k, $primaryValue, $tieValue)
            {
                $q->orWhere(function ($q) use ($k, $primaryValue, $tieValue)
                {
                    $q->where($k->primaryColumn, '=', $primaryValue)
                        ->where($k->tieColumn, '>', $tieValue);
                });
            });
    }

    // -------------------------------------------------------------------------
    // Encode / decode
    // -------------------------------------------------------------------------

    /**
     * Gera o cursor para a próxima página a partir do último item retornado.
     * Retorna null quando a página retornada for menor que o limit (última página).
     */
    private function buildNextCursor(Collection $items, int $limit, Keyset $k): ?string
    {
        if ($items->count() < $limit)
        {
            return null;
        }

        $last = $items->last();
        $primaryValue = $last->{$k->primaryAttribute};

        // Formata o valor da coluna primária (geralmente uma data).
        if ($k->primaryFormat !== null)
        {
            $primaryFormatted = $primaryValue instanceof \DateTimeInterface
                ? $primaryValue->format($k->primaryFormat)
                : (string) $primaryValue;
        }
        else
        {
            $primaryFormatted = (string) $primaryValue;
        }

        $payload = [$k->primaryKey => $primaryFormatted];

        if ($k->tieKey !== null && $k->tieAttribute !== null)
        {
            $tieValue = $last->{$k->tieAttribute};
            $payload[$k->tieKey] = $k->tieType === 'int' ? (int) $tieValue : (string) $tieValue;
        }

        return $this->encode($payload);
    }

    /**
     * Decodifica o cursor e retorna ['primary' => ..., 'tie' => ...] ou null.
     * Aplica normalização de fuso (Carbon) quando `$k->normalizeDate` for true.
     */
    private function decodeToArray(?string $cursor, Keyset $k): ?array
    {
        if (! $cursor)
        {
            return null;
        }

        $decoded = json_decode($this->base64urlDecode($cursor), true);

        if (! is_array($decoded) || empty($decoded[$k->primaryKey]))
        {
            return null;
        }

        $primaryRaw = (string) $decoded[$k->primaryKey];

        // Normaliza fuso quando necessário (contratações gravam timestamp sem tz).
        $primaryValue = $k->normalizeDate
            ? Carbon::parse($primaryRaw)->timezone((string) config('app.timezone'))->format($k->primaryFormat ?? 'Y-m-d H:i:s')
            : $primaryRaw;

        $tieValue = null;
        if ($k->tieKey !== null && isset($decoded[$k->tieKey]))
        {
            $tieValue = $k->tieType === 'int'
                ? (int) $decoded[$k->tieKey]
                : (string) $decoded[$k->tieKey];
        }

        return ['primary' => $primaryValue, 'tie' => $tieValue];
    }

    /**
     * Codifica um array associativo como cursor base64url.
     *
     * @param  array<string, mixed>  $payload
     */
    private function encode(array $payload): string
    {
        return rtrim(strtr(base64_encode((string) json_encode($payload, JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
    }

    /**
     * Decodifica um cursor base64url para a string JSON original.
     * Lida com espaços (alguns proxies convertem `+` em espaço) e padding ausente.
     */
    private function base64urlDecode(string $cursor): string
    {
        $normalized = strtr(str_replace(' ', '+', $cursor), '-_', '+/');
        $padding = strlen($normalized) % 4;

        if ($padding > 0)
        {
            $normalized .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($normalized, true);

        return $decoded !== false ? $decoded : (string) base64_decode($cursor);
    }
}
