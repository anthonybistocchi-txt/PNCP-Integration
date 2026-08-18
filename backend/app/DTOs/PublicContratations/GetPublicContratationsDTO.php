<?php

namespace App\DTOs\PublicContratations;

use App\Http\Requests\PublicContratations\GetPublicContratationsRequest;

final class GetPublicContratationsDTO
{
    public function __construct(
        public readonly string $dataInicial,
        public readonly string $dataFinal,
        public readonly int|string $codigoModalidadeContratacao,
        public readonly ?string $uf,
        public readonly int|string|null $codigoMunicipioIbge,
        public readonly int|string|null $pagina,
        public readonly int|string|null $tamanhoPagina,
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
