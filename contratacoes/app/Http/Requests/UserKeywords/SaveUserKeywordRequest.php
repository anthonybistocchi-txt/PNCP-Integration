<?php

namespace App\Http\Requests\UserKeywords;

use App\DTOs\UserKeywords\SaveUserKeywordDTO;
use App\Models\UserKeywords\UserKeyword;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveUserKeywordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id'         => 'nullable|int',
            'keyword'    => 'required_without:keywords|string|max:255',
            'keywords'   => 'required_without:keyword|array|min:1',
            'keywords.*' => 'required|string|max:255',
            'type'       => ['nullable', 'string', Rule::in([UserKeyword::TYPE_INCLUDE, UserKeyword::TYPE_EXCLUDE])],
        ];
    }

    public function messages(): array
    {
        return [
            'id.nullable'               => 'O ID deve ser um número inteiro.',
            'keyword.required_without'  => 'Informe keyword ou keywords.',
            'keyword.string'            => 'A palavra-chave deve ser um texto.',
            'keyword.max'               => 'A palavra-chave não pode ter mais de 255 caracteres.',
            'keywords.required_without' => 'Informe keywords ou keyword.',
            'keywords.array'            => 'As palavras-chave devem ser enviadas em um array.',
            'keywords.min'              => 'Informe ao menos uma palavra-chave.',
            'keywords.*.required'       => 'Cada palavra-chave é obrigatória.',
            'keywords.*.string'         => 'Cada palavra-chave deve ser um texto.',
            'keywords.*.max'            => 'Cada palavra-chave não pode ter mais de 255 caracteres.',
            'type.in'                   => 'O tipo deve ser "include" ou "exclude".',
        ];
    }

    public function toDTO(int $userId, ?int $id = null): SaveUserKeywordDTO
    {
        $keywords = $this->validated('keywords') ?? [$this->validated('keyword')];
        $keywords = array_values(array_unique(array_filter(array_map(
            static fn(string $keyword): string => trim($keyword),
            $keywords
        ))));

        return new SaveUserKeywordDTO(
            userId:   $userId,
            keywords: $keywords,
            type:     $this->validated('type') ?? UserKeyword::TYPE_INCLUDE,
            id:       $this->validated('id') ?? $id
        );
    }
}
