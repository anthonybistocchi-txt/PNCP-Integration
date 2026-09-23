<?php

namespace App\Http\Requests\Pca;

use App\DTOs\Pca\ListPcaItemsDTO;
use Illuminate\Foundation\Http\FormRequest;

class ListPcaItemsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Autenticação já tratada via middleware se necessário
    }

    public function rules(): array
    {
        return [
            'ano' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'cnpj' => ['nullable', 'string', 'max:18'],
            'categoriaItem' => ['nullable', 'integer'],
            'uf' => ['nullable', 'string', 'size:2'],
            'municipio' => ['nullable', 'string', 'max:100'],
            'search' => ['nullable', 'string', 'max:100'],
            'cursor' => ['nullable', 'string'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }

    public function toDTO(): ListPcaItemsDTO
    {
        return new ListPcaItemsDTO(
            ano: $this->input('ano') ? (int) $this->input('ano') : null,
            cnpj: $this->input('cnpj'),
            categoriaItem: $this->input('categoriaItem') ? (int) $this->input('categoriaItem') : null,
            uf: $this->input('uf'),
            municipio: $this->input('municipio'),
            search: $this->input('search'),
            cursor: $this->input('cursor'),
            limit: $this->input('limit', 15)
        );
    }
}
