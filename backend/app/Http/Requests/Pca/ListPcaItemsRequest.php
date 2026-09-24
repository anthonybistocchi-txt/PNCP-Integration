<?php

namespace App\Http\Requests\Pca;

use App\DTOs\Pca\ListPcaItemsDTO;
use Illuminate\Foundation\Http\FormRequest;

class ListPcaItemsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'ano'           => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'cnpj'          => ['nullable', 'string', 'max:18'],
            'categoriaItem' => ['nullable', 'integer'],
            'search'        => ['nullable', 'string', 'max:100'],
            'cursor'        => ['nullable', 'string'],
            'limit'         => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }

    public function toDTO(): ListPcaItemsDTO
    {
        $validated = $this->validated();

        return new ListPcaItemsDTO(
            ano:           isset($validated['ano']) ? (int) $validated['ano'] : null,
            cnpj:          $validated['cnpj'] ?? null,
            categoriaItem: isset($validated['categoriaItem']) ? (int) $validated['categoriaItem'] : null,
            search:        $validated['search'] ?? null,
            cursor:        $validated['cursor'] ?? null,
            limit:         (int) ($validated['limit'] ?? 15),
        );
    }

    public function messages(): array
    {
        return [
            'ano.integer'           => 'O ano deve ser um número inteiro.',
            'ano.min'               => 'O ano deve ser maior ou igual a 2000.',
            'ano.max'               => 'O ano deve ser menor ou igual a 2100.',
            'cnpj.string'           => 'O CNPJ deve ser um texto.',
            'cnpj.max'              => 'O CNPJ deve ter no máximo 18 caracteres.',
            'categoriaItem.integer' => 'A categoria do item deve ser um número inteiro.',
            'search.string'         => 'O termo de busca deve ser um texto.',
            'search.max'            => 'O termo de busca deve ter no máximo 100 caracteres.',
            'cursor.string'         => 'O cursor deve ser um texto.',
            'limit.integer'         => 'O limite deve ser um número inteiro.',
            'limit.min'             => 'O limite mínimo por página é 1.',
            'limit.max'             => 'Por motivos de performance, o limite máximo por página é 50.',
        ];
    }
}
