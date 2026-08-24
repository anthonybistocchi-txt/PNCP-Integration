<?php

namespace App\DTOs\PublicContratations;

final class ListPublicContratationsDTO
{
    public function __construct(
        public readonly string  $dataInicial,
        public readonly string  $dataFinal,
        public readonly int     $codigoModalidadeContratacao,
        public readonly ?string $uf                                = null,
        public readonly ?int    $codigoMunicipioIbge               = null,
        public readonly ?string $poderId                           = null,
        public readonly ?string $esferaId                          = null,
        public readonly ?int    $situacaoCompraId                  = null,
        public readonly ?int    $modoDisputaId                     = null,
        public readonly ?int    $tipoInstrumentoConvocatorioCodigo = null,
        public readonly ?int    $fontesOrcamentarias               = null,
        public readonly string  $tipoData                          = 'publicacao',

        // Keyset Pagination — substitui pagina/skip
        // O cursor é um Base64 com o último (data_publicacao_pncp, numero_controle_pncp) visto.
        // null = primeira página.
        public readonly ?string $cursor  = null,
        public readonly int     $limit   = 15,
    ) {}
}

