<?php

namespace App\Repositories;

use App\DTOs\PublicContratations\GetPublicContratationsDTO;
use App\DTOs\PublicContratations\GetRelevantContratationsDTO;
use App\Models\PublicContratation;
use Illuminate\Support\Collection;

class PublicContratationRepository
{
    /**
     * Consulta o banco de dados local usando os filtros do DTO.
     *
     * Usa Keyset Pagination (cursor-based) ao invés de OFFSET.
     * Com OFFSET, a página 500 obriga o PostgreSQL a ler e descartar 14.000+ linhas.
     * Com cursor, a query sempre localiza diretamente o ponto de partida pelo índice.
     *
     * Formato do cursor: Base64(JSON({last_date, last_numero_controle}))
     * Ordenação estável: data_publicacao_pncp DESC, numero_controle_pncp ASC
     */
    public function getByFilters(GetPublicContratationsDTO $dto): array
    {
        $query = PublicContratation::query()
            ->when($dto->dataInicial && $dto->dataFinal, function ($query) use ($dto) {
                $coluna = $dto->tipoData === 'atualizacao' ? 'data_atualizacao_global' : 'data_publicacao_pncp';

                $query->whereBetween($coluna, [
                    $this->formatDate($dto->dataInicial, '00:00:00'),
                    $this->formatDate($dto->dataFinal, '23:59:59'),
                ]);
            })
            ->when($dto->uf, fn($q, $uf)                                  => $q->where('uf', $uf))
            ->when($dto->codigoMunicipioIbge, fn($q, $ibge)               => $q->where('codigo_municipio_ibge', $ibge))
            ->when($dto->codigoModalidadeContratacao, fn($q, $modalidade) => $q->where('codigo_modalidade_contratacao', $modalidade))
            ->when($dto->poderId, fn($q, $val)                            => $q->where('payload_completo->orgaoEntidade->poderId', $val))
            ->when($dto->esferaId, fn($q, $val)                           => $q->where('payload_completo->orgaoEntidade->esferaId', $val))
            ->when($dto->situacaoCompraId, fn($q, $val)                   => $q->where('payload_completo->situacaoCompraId', (int) $val))
            ->when($dto->modoDisputaId, fn($q, $val)                      => $q->where('payload_completo->modoDisputaId', (int) $val))
            ->when($dto->tipoInstrumentoConvocatorioCodigo, fn($q, $val)  => $q->where('payload_completo->tipoInstrumentoConvocatorioCodigo', (int) $val))
            ->when($dto->fontesOrcamentarias, fn($q, $val)                => $q->whereJsonContains('payload_completo->fontesOrcamentarias', ['codigo' => (int) $val]));

        // Aplica o cursor para pular registros já vistos.
        // O cursor encoda o último (data_publicacao_pncp, numero_controle_pncp) recebido.
        $cursorData = $this->decodeCursor($dto->cursor);

        if ($cursorData) 
        {
            $query->where(function ($q) use ($cursorData) {
                $q->where('data_publicacao_pncp', '<', $cursorData['date'])
                  ->orWhere(function ($q) use ($cursorData) {
                      $q->where('data_publicacao_pncp', '=', $cursorData['date'])
                        ->where('numero_controle_pncp', '>', $cursorData['numero_controle']);
                  });
            });
        }

        $data = $query
            ->orderBy('data_publicacao_pncp', 'desc')
            ->orderBy('numero_controle_pncp', 'asc')
            ->limit($dto->limit)
            ->get();

        $nextCursor = $this->buildNextCursor($data, $dto->limit);

        return [
            'data'        => $data,
            'count_page'  => $data->count(),
            'next_cursor' => $nextCursor,
            'has_more'    => $nextCursor !== null,
        ];
    }

    /**
     * Busca licitações relevantes baseadas num array de palavras chaves
     */
    public function getRelevantByKeywords(array $keywords, GetRelevantContratationsDTO $dto): array
    {
        if (empty($keywords)) {
            return [
                'data'        => collect([]),
                'count_page'  => 0,
                'next_cursor' => null,
                'has_more'    => false,
            ];
        }

        // tsquery format for OR: word1 | word2
        $tsQueryStr = implode(' | ', $keywords);

        $query = PublicContratation::query()
            ->where('situacao_compra_id', 1) // Apenas registros ativos
            ->whereRaw(
                "to_tsvector('portuguese', objeto_compra) @@ to_tsquery('portuguese', ?)",
                [$tsQueryStr]
            );

        $cursorData = $this->decodeCursor($dto->cursor);

        if ($cursorData) 
        {
            $query->where(function ($q) use ($cursorData) {
                $q->where('data_publicacao_pncp', '<', $cursorData['date'])
                  ->orWhere(function ($q) use ($cursorData) {
                      $q->where('data_publicacao_pncp', '=', $cursorData['date'])
                        ->where('numero_controle_pncp', '>', $cursorData['numero_controle']);
                  });
            });
        }

        $data = $query
            ->orderBy('data_publicacao_pncp', 'desc')
            ->orderBy('numero_controle_pncp', 'asc')
            ->limit($dto->limit)
            ->get();

        $nextCursor = $this->buildNextCursor($data, $dto->limit);

        return [
            'data'        => $data,
            'count_page'  => $data->count(),
            'next_cursor' => $nextCursor,
            'has_more'    => $nextCursor !== null,
        ];
    }

    /**
     * Gera o cursor para a próxima página a partir do último item retornado.
     * Retorna null quando não há mais páginas (resultado menor que o limit solicitado).
     */
    private function buildNextCursor(Collection $data, int $limit): ?string
    {
        if ($data->count() < $limit) {
            return null;
        }

        $last = $data->last();

        return base64_encode(json_encode([
            'date'            => $last->data_publicacao_pncp,
            'numero_controle' => $last->numero_controle_pncp,
        ]));
    }

    /**
     * Decodifica o cursor recebido do cliente.
     * Retorna null se o cursor for inválido ou ausente (primeira página).
     */
    private function decodeCursor(?string $cursor): ?array
    {
        if (!$cursor) {
            return null;
        }

        $decoded = json_decode(base64_decode($cursor), true);

        if (!is_array($decoded) || empty($decoded['date']) || empty($decoded['numero_controle'])) {
            return null;
        }

        return $decoded;
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
