<?php

namespace App\Http\Requests\UserContratationFilters;

use App\DTOs\UserContratationFilters\SaveUserContratationFilterDTO;
use App\Models\UserContratationFilters\UserContratationFilter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveUserContratationFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'filter_type' => ['required', 'string', Rule::in(UserContratationFilter::TYPES)],
            'values'      => 'required|array|min:1',
            'values.*'    => 'required|string|max:100',
        ];
    }

    public function messages(): array
    {
        return [
            'filter_type.required' => 'O tipo do filtro é obrigatório.',
            'filter_type.in'       => 'Tipo de filtro inválido. Valores aceitos: ' . implode(', ', UserContratationFilter::TYPES) . '.',
            'values.required'      => 'Informe ao menos um valor para o filtro.',
            'values.array'         => 'Os valores devem ser enviados em um array.',
            'values.min'           => 'Informe ao menos um valor para o filtro.',
            'values.*.required'    => 'Cada valor do filtro é obrigatório.',
            'values.*.string'      => 'Cada valor do filtro deve ser um texto.',
            'values.*.max'         => 'Cada valor do filtro não pode ter mais de 100 caracteres.',
        ];
    }

    public function toDTO(int $userId): SaveUserContratationFilterDTO
    {
        $filterValues = array_values(array_unique(array_filter(array_map(
            static fn(string $filterValue): string => trim($filterValue),
            $this->validated('values')
        ))));

        return new SaveUserContratationFilterDTO(
            userId:     $userId,
            filterType: $this->validated('filter_type'),
            values:     $filterValues,
        );
    }
}
