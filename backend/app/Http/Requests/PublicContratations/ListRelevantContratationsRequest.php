<?php

namespace App\Http\Requests\PublicContratations;

use App\DTOs\PublicContratations\ListRelevantContratationsDTO;
use Illuminate\Foundation\Http\FormRequest;

class ListRelevantContratationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cursor' => 'nullable|string',
            'limit'  => 'nullable|integer|min:1|max:50',
        ];
    }

    public function messages(): array
    {
        return [
            'limit.min' => 'O limite mínimo por página é 1.',
            'limit.max' => 'Por motivos de performance, o limite máximo por página é 50.',
        ];
    }

    public function toDTO(): ListRelevantContratationsDTO
    {
        $validated = $this->validated();

        return new ListRelevantContratationsDTO(
            cursor: $validated['cursor'] ?? null,
            limit:  (int) ($validated['limit'] ?? 15)
        );
    }
}

