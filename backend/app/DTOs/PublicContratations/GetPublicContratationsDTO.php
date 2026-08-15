<?php

namespace App\DTOs\PublicContratations;

use App\Http\Requests\PublicContratations\GetPublicContratationsRequest;

final class GetPublicContratationsDTO
{
    public function __construct(
        public readonly string $dataInicial,
        public readonly string $dataFinal,
        public readonly string $codigoModalidadeContratacao,
        public readonly ?string $uf,
        public readonly ?string $codigoMunicipioIbge,
        public readonly ?string $pagina,
        public readonly ?string $tamanhoPagina,
    ) {}

    public static function fromRequest(GetPublicContratationsRequest $request): self
    {
        return new self(
            $request->dataInicial,
            $request->dataFinal,
            $request->codigoModalidadeContratacao,
            $request->uf,
            $request->codigoMunicipioIbge,
            $request->pagina,
            $request->tamanhoPagina,
        );
    }
}
