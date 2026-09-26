<?php

namespace App\Actions\Orgaos;

use App\DTOs\Orgaos\GetOrgaoDTO;
use App\Models\Orgaos\Orgao;
use App\Services\Orgaos\OrgaoService;

class GetOrgaoAction
{
    public function __construct(
        private OrgaoService $service
    ) {}

    public function show(GetOrgaoDTO $dto): Orgao
    {
        return $this->service->findWithPcaData($dto->cnpj);
    }
}
