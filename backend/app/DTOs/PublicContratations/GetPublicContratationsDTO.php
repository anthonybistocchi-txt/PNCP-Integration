<?php

namespace App\DTOs\PublicContratations;

use App\Http\Requests\PublicContratations\GetPublicContratationsRequest;

final class GetPublicContratationsDTO
{
    public function __construct(
        public readonly string $dataInicial,
        public readonly string $dataFinal,
        public readonly int $codigoModalidadeContratacao,
        public readonly ?string $uf,
        public readonly ?int $codigoMunicipioIbge = null,
        public readonly ?int $pagina = null,
        public readonly ?int $tamanhoPagina = null,
        public readonly ?string $poderId = null,
        public readonly ?string $esferaId = null,
        public readonly ?int $situacaoCompraId = null,
        public readonly ?int $modoDisputaId = null,
        public readonly ?int $tipoInstrumentoConvocatorioCodigo = null,
        public readonly ?int $fontesOrcamentarias = null,
    ) {}

    public static function fromRequest(GetPublicContratationsRequest $request): self
    {
        return new self(
            $request->dataInicial,
            $request->dataFinal,
            $request->codigoModalidadeContratacao,
            $request->uf,
            $request->codigoMunicipioIbge ?? null,
            $request->pagina ?? null,
            $request->tamanhoPagina ?? null,
            $request->poderId ?? null,
            $request->esferaId ?? null,
            $request->situacaoCompraId ?? null,
            $request->modoDisputaId ?? null,
            $request->tipoInstrumentoConvocatorioCodigo ?? null,
            $request->fontesOrcamentarias ?? null,
        );
    }
}
