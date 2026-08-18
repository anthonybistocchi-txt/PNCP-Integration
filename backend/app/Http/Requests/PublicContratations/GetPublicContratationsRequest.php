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
            'codigoModalidadeContratacao' => 'required|integer',
            'uf'                          => 'nullable|string|max:2',
            'codigoMunicipioIbge'         => 'nullable|integer',
            'pagina'                            => 'nullable|integer',
            'tamanhoPagina'                     => 'nullable|integer',
            'poderId'                           => 'nullable|string|max:2',
            'esferaId'                          => 'nullable|string|max:2',
            'situacaoCompraId'                  => 'nullable|integer',
            'modoDisputaId'                     => 'nullable|integer',
            'tipoInstrumentoConvocatorioCodigo' => 'nullable|integer',
            'fontesOrcamentarias'               => 'nullable|integer',
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
            'codigoModalidadeContratacao.integer'  => 'O código da modalidade de contratação deve ser um número inteiro.',
            'uf.nullable'                          => 'A UF deve ser uma string.',
            'uf.string'                            => 'A UF deve ser uma string.',
            'uf.max'                               => 'A UF deve ter no máximo 2 caracteres.',
            'codigoMunicipioIbge.nullable'         => 'O código do município IBGE deve ser um inteiro.',
            'codigoMunicipioIbge.integer'          => 'O código do município IBGE deve ser um número inteiro.',
            'pagina.nullable'                      => 'A página deve ser um inteiro.',
            'pagina.integer'                       => 'A página deve ser um número inteiro.',
            'tamanhoPagina.nullable'               => 'O tamanho da página deve ser um inteiro.',
            'tamanhoPagina.integer'                => 'O tamanho da página deve ser um número inteiro.',
        ];
    }
}
