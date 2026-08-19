<?php

namespace App\Repositories;

use App\DTOs\PublicContratations\GetPublicContratationsDTO;
use App\Models\PublicContratation;

class PublicContratationRepository
{
    /**
     * Consulta apenas o banco de dados local usando os filtros passados.
     * Como a tabela crescerá muito, é vital usar os índices adequadamente.
     */
    public function getByFilters(GetPublicContratationsDTO $dto): array
    {
        $query = PublicContratation::query()
            ->when($dto->dataInicial && $dto->dataFinal, function ($query) use ($dto) {
                $coluna = $dto->tipoData === 'atualizacao' ? 'data_atualizacao_global' : 'data_publicacao_pncp';
                
                $query->whereBetween($coluna, [
                    $this->formatDate($dto->dataInicial, '00:00:00'),
                    $this->formatDate($dto->dataFinal, '23:59:59')
                ]);
            })
            ->when($dto->uf, fn($query, $uf)                                  => $query->where('uf', $uf))
            ->when($dto->codigoMunicipioIbge, fn($query, $ibge)               => $query->where('codigo_municipio_ibge', $ibge))
            ->when($dto->codigoModalidadeContratacao, fn($query, $modalidade) => $query->where('codigo_modalidade_contratacao', $modalidade))
            ->when($dto->poderId, fn($query, $val)                            => $query->where('payload_completo->orgaoEntidade->poderId', $val))
            ->when($dto->esferaId, fn($query, $val)                           => $query->where('payload_completo->orgaoEntidade->esferaId', $val))
            ->when($dto->situacaoCompraId, fn($query, $val)                   => $query->where('payload_completo->situacaoCompraId', (int) $val))
            ->when($dto->modoDisputaId, fn($query, $val)                      => $query->where('payload_completo->modoDisputaId', (int) $val))
            ->when($dto->tipoInstrumentoConvocatorioCodigo, fn($query, $val)  => $query->where('payload_completo->tipoInstrumentoConvocatorioCodigo', (int) $val))
            ->when($dto->fontesOrcamentarias, function ($query, $val) {
                $query->whereJsonContains('payload_completo->fontesOrcamentarias', ['codigo' => (int) $val]);
            });

        // Simulação de paginação ou limites (no modelo final deve-se usar ->paginate())
        $take  = $dto->tamanhoPagina ? (int) $dto->tamanhoPagina : 15;
        $skip  = $dto->pagina        ? ((int) $dto->pagina - 1) * $take : 0;

        // Clona a query ANTES do skip/take para pegar o total real sem limite
        $cloneQuery = clone $query;
        $total = $cloneQuery->count();

        // Faz o get retornando uma Collection de OBJETOS (Models), e não array
        $data = $query->skip($skip)->take($take)->get();

        return [
            'data'        => $data,     
            'count'       => $total,
            'total_pages' => $take > 0 ? ceil($total / $take) : 1
        ];
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
