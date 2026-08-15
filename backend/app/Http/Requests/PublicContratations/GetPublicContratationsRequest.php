<?php

namespace App\Http\Requests\PublicContratations;

use App\DTOs\PublicContratations\GetPublicContratationsDTO;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class GetPublicContratationsRequest extends FormRequest
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
     *
     * 
     */
    public function rules(): array
    {
        return [
            'dataInicial'                 => 'required|date_format:Ymd|before_or_equal:dataFinal',
            'dataFinal'                   => 'required|date_format:Ymd|after_or_equal:dataInicial',
            'codigoModalidadeContratacao' => 'required|string|max:255',
            'uf'                          => 'nullable|string|max:2',
            'codigoMunicipioIbge'         => 'nullable|string|max:7',
            'pagina'                      => 'nullable|string|max:255',
            'tamanhoPagina'               => 'nullable|string|max:255',
        ];
    }

    public function toDTO(): GetPublicContratationsDTO
    {
        return new GetPublicContratationsDTO(
            dataInicial: $this->validated('dataInicial'),
            dataFinal: $this->validated('dataFinal'),
            codigoModalidadeContratacao: $this->validated('codigoModalidadeContratacao'),
            uf: strtoupper($this->validated('uf')),
            codigoMunicipioIbge: $this->validated('codigoMunicipioIbge'),
            pagina: $this->validated('pagina'),
            tamanhoPagina: $this->validated('tamanhoPagina'),
        );
    }

    public function messages()
    {
        return [
            'dataInicial.required'                 => 'A data inicial é obrigatória.',
            'dataInicial.date_format'              => 'A data inicial deve ser no formato Ymd.',
            'dataFinal.required'                   => 'A data final é obrigatória.',
            'dataFinal.date_format'                => 'A data final deve ser no formato Ymd.',
            'codigoModalidadeContratacao.required' => 'O código da modalidade de contratação é obrigatório.',
            'codigoModalidadeContratacao.string'   => 'O código da modalidade de contratação deve ser uma string.',
            'codigoModalidadeContratacao.max'      => 'O código da modalidade de contratação deve ter no máximo 255 caracteres.',
            'uf.nullable'                          => 'A UF deve ser uma string.',
            'uf.string'                            => 'A UF deve ser uma string.',
            'uf.max'                               => 'A UF deve ter no máximo 2 caracteres.',
            'codigoMunicipioIbge.nullable'         => 'O código do município IBGE deve ser uma string.',
            'codigoMunicipioIbge.string'           => 'O código do município IBGE deve ser uma string.',
            'codigoMunicipioIbge.max'              => 'O código do município IBGE deve ter no máximo 7 caracteres.',
            'pagina.nullable'                      => 'A página deve ser uma string.',
            'pagina.string'                        => 'A página deve ser uma string.',
            'pagina.max'                           => 'A página deve ter no máximo 255 caracteres.',
            'tamanhoPagina.nullable'               => 'O tamanho da página deve ser uma string.',
            'tamanhoPagina.string'                 => 'O tamanho da página deve ser uma string.',
            'tamanhoPagina.max'                    => 'O tamanho da página deve ter no máximo 255 caracteres.',
        ];
    }
}
