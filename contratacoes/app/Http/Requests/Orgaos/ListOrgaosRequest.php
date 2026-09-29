<?php

namespace App\Http\Requests\Orgaos;

use App\DTOs\Orgaos\ListOrgaosDTO;
use Illuminate\Foundation\Http\FormRequest;

class ListOrgaosRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cursor' => ['nullable', 'string'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }

    public function toDTO(): ListOrgaosDTO
    {
        $validated = $this->validated();

        return new ListOrgaosDTO(
            cursor: $validated['cursor'] ?? null,
            limit: (int) ($validated['limit'] ?? 15),
        );
    }
}
