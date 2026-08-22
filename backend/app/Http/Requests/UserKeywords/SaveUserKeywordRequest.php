<?php

namespace App\Http\Requests\UserKeywords;

use App\DTOs\UserKeywords\SaveUserKeywordDTO;
use Illuminate\Foundation\Http\FormRequest;

class SaveUserKeywordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'keyword' => 'required|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'keyword.required' => 'A palavra-chave é obrigatória.',
            'keyword.string'   => 'A palavra-chave deve ser um texto.',
            'keyword.max'      => 'A palavra-chave não pode ter mais de 255 caracteres.',
        ];
    }

    public function toDTO(int $userId, ?int $id = null): SaveUserKeywordDTO
    {
        return new SaveUserKeywordDTO(
            userId: $userId,
            keyword: $this->validated('keyword'),
            id: $id
        );
    }
}
