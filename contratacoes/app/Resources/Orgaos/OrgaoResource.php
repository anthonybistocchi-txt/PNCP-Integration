<?php

namespace App\Resources\Orgaos;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrgaoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'cnpj' => $this->cnpj,
            'razao_social' => $this->razao_social,
            'poder' => $this->poder,
            'esfera' => $this->esfera,
            'ativo' => $this->ativo,
        ];
    }
}
