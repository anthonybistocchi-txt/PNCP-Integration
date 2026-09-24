<?php

namespace App\DTOs\Pncp;

class PncpOrgaoUnidadeData
{
    public function __construct(
        public readonly string $cnpjOrgao,
        public readonly int $anoPca,
        public readonly string $codigoUnidade,
        public readonly ?string $nomeUnidade,
        public readonly ?string $ufSigla,
        public readonly ?int $codigoIbge,
        public readonly ?string $nomeMunicipio,
        public readonly ?string $poder,
        public readonly ?string $esfera,
        public readonly ?object $payloadCompleto
    ) {}
}
