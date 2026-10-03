<?php

namespace App\Repositories\Contratacoes;

use App\DTOs\Paginate\PaginatedResultDTO;
use App\DTOs\Contratacoes\ListContratacoesDTO;
use App\DTOs\Contratacoes\ListRelevantContratacoesDTO;
use App\DTOs\Contratacoes\PersonalizedContratacaoFiltersDTO;
use App\Models\Contratacoes\Contratacao;
use App\Support\Pagination\Keyset;
use App\Support\Pagination\KeysetPaginator;
use App\Support\Search\ContratationKeywordFilter;
use DateTimeInterface;

class ContratacaoRepository
{
    public function __construct(
        private ContratationKeywordFilter $keywordFilter,
        private KeysetPaginator $paginator,
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
    public function list(ListContratacoesDTO $dto): PaginatedResultDTO
    {
        $query = Contratacao::query()
            ->when($dto->dataInicial && $dto->dataFinal, function ($query) use ($dto)
            {
                $colunaData = $dto->tipoData === 'atualizacao' ? 'data_atualizacao_global' : 'data_publicacao_pncp';

                $query->whereBetween($colunaData, [
                    $this->formatDate($dto->dataInicial, '00:00:00'),
                    $this->formatDate($dto->dataFinal, '23:59:59'),
                ]);
            })
            ->when($dto->uf, fn ($query, $uf) => $query->where('uf', $uf))
            ->when($dto->codigoMunicipioIbge, fn ($query, $codigoIbge) => $query->where('codigo_municipio_ibge', $codigoIbge))
            ->when($dto->codigoModalidadeContratacao, fn ($query, $codigoModalidade) => $query->where('codigo_modalidade_contratacao', $codigoModalidade))
            ->when($dto->poderId, fn ($query, $poderId) => $query->where('payload_completo->orgaoEntidade->poderId', $poderId))
            ->when($dto->esferaId, fn ($query, $esferaId) => $query->where('payload_completo->orgaoEntidade->esferaId', $esferaId))
            ->when($dto->situacaoCompraId, fn ($query, $situacaoCompraId) => $query->where('situacao_compra_id', (int) $situacaoCompraId))
            ->when($dto->modoDisputaId, fn ($query, $modoDisputaId) => $query->where('payload_completo->modoDisputaId', (int) $modoDisputaId))
            ->when($dto->tipoInstrumentoConvocatorioCodigo, fn ($query, $tipoInstrumentoCodigo) => $query->where('payload_completo->tipoInstrumentoConvocatorioCodigo', (int) $tipoInstrumentoCodigo))
            ->when($dto->fontesOrcamentarias, fn ($query, $codigoFonteOrcamentaria) => $query->whereJsonContains('payload_completo->fontesOrcamentarias', ['codigo' => (int) $codigoFonteOrcamentaria]));

        return $this->paginator->paginate($query, $dto->cursor, $dto->limit, Keyset::contratacoes());
    }

    /**
     * Busca personalizada: dimensões salvas do cliente + palavras do modo
     * pedido (só inclusão, só exclusão, ou A AND NOT B).
     */
    public function searchPersonalized(PersonalizedContratacaoFiltersDTO $filters, ListRelevantContratacoesDTO $dto): PaginatedResultDTO
    {
        if ($filters->hasNoActiveFilters())
        {
            return $this->emptyResult();
        }

        // Sem filtro explícito de situação, mantém o comportamento padrão: só ativas.
        $situacoesCompraPermitidas = $filters->situacaoCompraId ?: [1];

        $query = Contratacao::query()
            ->whereIn('situacao_compra_id', $situacoesCompraPermitidas)
            ->when($filters->uf, fn ($query, $ufsPermitidas)                                             => $query->whereIn('uf', $ufsPermitidas))
            ->when($filters->codigoModalidadeContratacao, fn ($query, $modalidadesPermitidas)            => $query->whereIn('codigo_modalidade_contratacao', $modalidadesPermitidas))
            ->when($filters->poderId, fn ($query, $poderesPermitidos)                                    => $query->whereIn('payload_completo->orgaoEntidade->poderId', $poderesPermitidos))
            ->when($filters->esferaId, fn ($query, $esferasPermitidas)                                   => $query->whereIn('payload_completo->orgaoEntidade->esferaId', $esferasPermitidas))
            ->when($filters->modoDisputaId, fn ($query, $modosDisputaPermitidos)                         => $query->whereIn('payload_completo->modoDisputaId', $modosDisputaPermitidos))
            ->when($filters->tipoInstrumentoConvocatorioCodigo, fn ($query, $tiposInstrumentoPermitidos) => $query->whereIn('payload_completo->tipoInstrumentoConvocatorioCodigo', $tiposInstrumentoPermitidos))
            ->when($filters->fontesOrcamentarias, function ($query, $fontesOrcamentariasPermitidas)
            {
                $query->where(function ($query) use ($fontesOrcamentariasPermitidas)
                {
                    foreach ($fontesOrcamentariasPermitidas as $codigoFonteOrcamentaria)
                    {
                        $query->orWhereJsonContains('payload_completo->fontesOrcamentarias', ['codigo' => (int) $codigoFonteOrcamentaria]);
                    }
                });
            });

        $this->keywordFilter->apply(
            $query,
            $filters->includeKeywords,
            $filters->excludeKeywords
        );

        return $this->paginator->paginate($query, $dto->cursor, $dto->limit, Keyset::contratacoes());
    }

    /**
     * Licitações da modalidade que ainda não tiveram documentos buscados.
     *
     * Com `$updatedSince`, recorta o que o ciclo de contratações acabou de
     * tocar (Fase 2). Sem a data, é a varredura de pendentes: qualquer
     * registro nulo, pelos mais recentes primeiro, com teto.
     *
     * @return list<array{numero_controle_pncp: string, orgao_cnpj: string, ano_compra: int, sequencial_compra: int}>
     */
    public function listPendingDocuments(int $modalidade, int $limit, ?DateTimeInterface $updatedSince = null): array
    {
        return Contratacao::query()
            ->select([
                'numero_controle_pncp',
                'orgao_cnpj',
                'ano_compra',
                'sequencial_compra',
            ])
            ->whereNull('documentos_sincronizados_em')
            ->where('codigo_modalidade_contratacao', $modalidade)
            ->when($updatedSince, fn ($query) => $query->where('updated_at', '>=', $updatedSince))
            ->whereNotNull('sequencial_compra')
            ->orderByDesc('data_publicacao_pncp')
            ->limit($limit)
            ->get()
            ->toArray();
    }

    /**
     * Do lote informado, os números de controle que já tiveram documentos sincronizados.
     *
     * @param  list<string>  $numerosControle
     * @return list<string>
     */
    public function numerosControleComDocumentos(array $numerosControle): array
    {
        if ($numerosControle === [])
        {
            return [];
        }

        return Contratacao::query()
            ->whereIn('numero_controle_pncp', $numerosControle)
            ->whereNotNull('documentos_sincronizados_em')
            ->pluck('numero_controle_pncp')
            ->all();
    }

    private function emptyResult(): PaginatedResultDTO
    {
        return new PaginatedResultDTO(
            data: collect([]),
            page: 1,
            count_page: 0,
            count: 0,
            next_cursor: null,
            has_more: false,
        );
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
