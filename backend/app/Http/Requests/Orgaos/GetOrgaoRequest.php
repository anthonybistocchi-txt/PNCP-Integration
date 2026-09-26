<?php

namespace App\Http\Requests\Orgaos;

use App\DTOs\Orgaos\GetOrgaoDTO;
use Illuminate\Foundation\Http\FormRequest;

class GetOrgaoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cnpj' => ['required', 'string', 'size:14'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['cnpj' => $this->route('cnpj')]);
    }

    public function toDTO(): GetOrgaoDTO
    {
        return new GetOrgaoDTO(cnpj: (string) $this->validated()['cnpj']);
    }
}
