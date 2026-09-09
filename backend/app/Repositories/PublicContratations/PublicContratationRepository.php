<?php

namespace App\Repositories\PublicContratations;

use App\DTOs\Paginate\PaginatedResultDTO;
use App\DTOs\PublicContratations\CursorDTO;
use App\DTOs\PublicContratations\ListPublicContratationsDTO;
use App\DTOs\PublicContratations\ListRelevantContratationsDTO;
use App\DTOs\PublicContratations\PersonalizedContratationFiltersDTO;
use App\Models\PublicContratations\PublicContratation;
use App\Support\Search\ContratationKeywordFilter;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class PublicContratationRepository
{
    public function __construct(
        private ContratationKeywordFilter $keywordFilter
    ) {}

    /**
     * Consulta o banco de dados local usando os filtros do DTO.
     *
     * Usa Keyset Pagination (cursor-based) ao invés de OFFSET.
     * Com OFFSET, a página 500 obriga o PostgreSQL a ler e descartar 14.000+ linhas.
     * Com cursor, a query sempre localiza diretamente o ponto de partida pelo índice.
     *
     * Formato do cursor: Base64 URL-safe de JSON({date: Y-m-d H:i:s, numero_controle})
     * Ordenação estável: data_publicacao_pncp DESC, numero_controle_pncp ASC
     */
    public function list(ListPublicContratationsDTO $dto): PaginatedResultDTO
    {
        $query = PublicContratation::query()
            ->when($dto->dataInicial && $dto->dataFinal, function ($query) use ($dto) {
                $colunaData = $dto->tipoData === 'atualizacao' ? 'data_atualizacao_global' : 'data_publicacao_pncp';

                $query->whereBetween($colunaData, [
                    $this->formatDate($dto->dataInicial, '00:00:00'),
                    $this->formatDate($dto->dataFinal, '23:59:59'),
                ]);
            })
            ->when($dto->uf, fn($query, $uf)                             => $query->where('uf', $uf))
            ->when($dto->codigoMunicipioIbge, fn($query, $codigoIbge)    => $query->where('codigo_municipio_ibge', $codigoIbge))
            ->when($dto->codigoModalidadeContratacao, fn($query, $codigoModalidade) => $query->where('codigo_modalidade_contratacao', $codigoModalidade))
            ->when($dto->poderId, fn($query, $poderId)                   => $query->where('payload_completo->orgaoEntidade->poderId', $poderId))
            ->when($dto->esferaId, fn($query, $esferaId)                 => $query->where('payload_completo->orgaoEntidade->esferaId', $esferaId))
            ->when($dto->situacaoCompraId, fn($query, $situacaoCompraId) => $query->where('situacao_compra_id', (int) $situacaoCompraId))
            ->when($dto->modoDisputaId, fn($query, $modoDisputaId)       => $query->where('payload_completo->modoDisputaId', (int) $modoDisputaId))
            ->when($dto->tipoInstrumentoConvocatorioCodigo, fn($query, $tipoInstrumentoCodigo) => $query->where('payload_completo->tipoInstrumentoConvocatorioCodigo', (int) $tipoInstrumentoCodigo))
            ->when($dto->fontesOrcamentarias, fn($query, $codigoFonteOrcamentaria) => $query->whereJsonContains('payload_completo->fontesOrcamentarias', ['codigo' => (int) $codigoFonteOrcamentaria]));

        return $this->paginateByCursor($query, $dto->cursor, $dto->limit);
    }

    /**
     * Busca personalizada: dimensões salvas do cliente + palavras do modo
     * pedido (só inclusão, só exclusão, ou A AND NOT B).
     */
    public function searchPersonalized(PersonalizedContratationFiltersDTO $filters, ListRelevantContratationsDTO $dto): PaginatedResultDTO
    {
        if ($filters->hasNoActiveFilters()) {
            return $this->emptyResult();
        }

        // Sem filtro explícito de situação, mantém o comportamento padrão: só ativas.
        $situacoesCompraPermitidas = $filters->situacaoCompraId ?: [1];

        $query = PublicContratation::query()
            ->whereIn('situacao_compra_id', $situacoesCompraPermitidas)
            ->when($filters->uf, fn($query, $ufsPermitidas)                             => $query->whereIn('uf', $ufsPermitidas))
            ->when($filters->codigoModalidadeContratacao, fn($query, $modalidadesPermitidas) => $query->whereIn('codigo_modalidade_contratacao', $modalidadesPermitidas))
            ->when($filters->poderId, fn($query, $poderesPermitidos)                     => $query->whereIn('payload_completo->orgaoEntidade->poderId', $poderesPermitidos))
            ->when($filters->esferaId, fn($query, $esferasPermitidas)                    => $query->whereIn('payload_completo->orgaoEntidade->esferaId', $esferasPermitidas))
            ->when($filters->modoDisputaId, fn($query, $modosDisputaPermitidos)          => $query->whereIn('payload_completo->modoDisputaId', $modosDisputaPermitidos))
            ->when($filters->tipoInstrumentoConvocatorioCodigo, fn($query, $tiposInstrumentoPermitidos) => $query->whereIn('payload_completo->tipoInstrumentoConvocatorioCodigo', $tiposInstrumentoPermitidos))
            ->when($filters->fontesOrcamentarias, function ($query, $fontesOrcamentariasPermitidas) {
                $query->where(function ($query) use ($fontesOrcamentariasPermitidas) {
                    foreach ($fontesOrcamentariasPermitidas as $codigoFonteOrcamentaria) {
                        $query->orWhereJsonContains('payload_completo->fontesOrcamentarias', ['codigo' => (int) $codigoFonteOrcamentaria]);
                    }
                });
            });

        $this->keywordFilter->apply(
            $query,
            $filters->includeKeywords,
            $filters->excludeKeywords
        );

        return $this->paginateByCursor($query, $dto->cursor, $dto->limit);
    }

    /**
     * Aplica keyset pagination (cursor-based) numa query já filtrada e
     * devolve o resultado paginado. Compartilhado por todas as buscas de
     * contratações para não duplicar a lógica de cursor/contagem.
     */
    private function paginateByCursor(Builder $query, ?string $cursor, int $limit): PaginatedResultDTO
    {
        $cursorData = $this->decodeCursor($cursor);

        // Clonamos a query base ANTES de aplicar orderBy, limit ou o cursor
        // para pegar o total real de registros que batem com os filtros principais.
        $baseQuery = clone $query;

        $totalCount = $baseQuery->count();
        $currentPage = 1;

        if ($cursorData) {
            // Para descobrir em qual "página" estamos,
            // contamos quantos registros ficaram para trás (antes do cursor na ordenação).
            $skippedQuery = clone $baseQuery;
            $skippedCount = $skippedQuery->where(function ($query) use ($cursorData) {
                $query->where('data_publicacao_pncp', '>', $cursorData->date)
                    ->orWhere(function ($query) use ($cursorData) {
                        $query->where('data_publicacao_pncp', '=', $cursorData->date)
                            ->where('numero_controle_pncp', '<=', $cursorData->numero_controle);
                    });
            })->count();

            $currentPage = (int) floor($skippedCount / $limit) + 1;

            $query->where(function ($query) use ($cursorData) {
                $query->where('data_publicacao_pncp', '<', $cursorData->date)
                    ->orWhere(function ($query) use ($cursorData) {
                        $query->where('data_publicacao_pncp', '=', $cursorData->date)
                            ->where('numero_controle_pncp', '>', $cursorData->numero_controle);
                    });
            });
        }

        $contratationsPage = $query
            ->orderBy('data_publicacao_pncp', 'desc')
            ->orderBy('numero_controle_pncp', 'asc')
            ->limit($limit)
            ->get();

        $nextCursor = $this->buildNextCursor($contratationsPage, $limit);

        return new PaginatedResultDTO(
            data:        $contratationsPage,
            page:        $currentPage,
            count_page:  $contratationsPage->count(),
            count:       $totalCount,
            next_cursor: $nextCursor,
            has_more:    $nextCursor !== null,
        );
    }

    private function emptyResult(): PaginatedResultDTO
    {
        return new PaginatedResultDTO(
            data:        collect([]),
            page:        1,
            count_page:  0,
            count:       0,
            next_cursor: null,
            has_more:    false,
        );
    }

    /**
     * Gera o cursor para a próxima página a partir do último item retornado.
     * Retorna null quando não há mais páginas (resultado menor que o limit solicitado).
     */
    private function buildNextCursor(Collection $contratationsPage, int $limit): ?string
    {
        if ($contratationsPage->count() < $limit) {
            return null;
        }

        $lastContratation = $contratationsPage->last();
        $publishedAt = $lastContratation->data_publicacao_pncp;

        return $this->encodeCursor(
            date: $publishedAt instanceof \DateTimeInterface
                ? $publishedAt->format('Y-m-d H:i:s')
                : (string) $publishedAt,
            numeroControle: (string) $lastContratation->numero_controle_pncp,
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

        if (!is_array($decodedCursor) || empty($decodedCursor['date']) || empty($decodedCursor['numero_controle'])) {
            return null;
        }

        return new CursorDTO(
            date:            $this->normalizeCursorDate((string) $decodedCursor['date']),
            numero_controle: (string) $decodedCursor['numero_controle'],
        );
    }

    /**
     * O banco guarda data_publicacao_pncp como timestamp sem timezone (horário de Brasília).
     * json_encode() de um Carbon gera ISO em UTC (ex: 2026-09-01T15:29:10.000000Z),
     * 3 horas à frente do valor persistido. A query então não descarta a 1ª página.
     */
    private function encodeCursor(string $date, string $numeroControle): string
    {
        $payload = json_encode([
            'date'            => $date,
            'numero_controle' => $numeroControle,
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

    private function normalizeCursorDate(string $date): string
    {
        return Carbon::parse($date)
            ->timezone((string) config('app.timezone'))
            ->format('Y-m-d H:i:s');
    }

    private function formatDate(string $dateString, string $time): string
    {
        // Converte de Ymd para Y-m-d H:i:s
        // Ex: 20260101 -> 2026-01-01 00:00:00
        $year  = substr($dateString, 0, 4);
        $month = substr($dateString, 4, 2);
        $day   = substr($dateString, 6, 2);

        return "{$year}-{$month}-{$day} {$time}";
    }
}
