<?php

namespace App\DTOs\Pncp;

class PncpOrgaoUnidadeData
{
    public function __construct(
        public readonly string $cnpjOrgao,
        public readonly string $codigoUnidade,
        public readonly ?string $nomeUnidade,
        public readonly ?string $ufSigla,
        public readonly ?int $codigoIbge,
        public readonly ?string $nomeMunicipio,
        public readonly ?string $poderId,
        public readonly ?string $esferaId,
        public readonly ?array $payloadCompleto
    ) {}
}
