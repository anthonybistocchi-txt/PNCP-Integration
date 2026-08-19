<?php

namespace App\Http\Requests\PublicContratations;

use App\DTOs\PublicContratations\GetPublicContratationsDTO;
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
     */
    public function rules(): array
    {
        return [
            'dataInicial'                       => 'required|date_format:Ymd|before_or_equal:dataFinal',
            'dataFinal'                         => 'required|date_format:Ymd|after_or_equal:dataInicial',
            'codigoModalidadeContratacao'       => 'required|integer',
            'uf'                                => 'nullable|string|max:2',
            'codigoMunicipioIbge'               => 'nullable|integer',
            'poderId'                           => 'nullable|string|max:2',
            'esferaId'                          => 'nullable|string|max:2',
            'situacaoCompraId'                  => 'nullable|integer',
            'modoDisputaId'                     => 'nullable|integer',
            'tipoInstrumentoConvocatorioCodigo' => 'nullable|integer',
            'fontesOrcamentarias'               => 'nullable|integer',
            'tipoData'                          => 'nullable|string|in:publicacao,atualizacao',

            // Keyset Pagination
            'cursor' => 'nullable|string',
            'limit'  => 'nullable|integer|min:1|max:50',
        ];
    }

    public function toDTO(): GetPublicContratationsDTO
    {
        $validated = $this->validated();

        return new GetPublicContratationsDTO(
            dataInicial:                 $validated['dataInicial'],
            dataFinal:                   $validated['dataFinal'],
            codigoModalidadeContratacao: (int) $validated['codigoModalidadeContratacao'],
            uf:                          isset($validated['uf']) ? strtoupper($validated['uf']) : null,
            codigoMunicipioIbge:         $validated['codigoMunicipioIbge'] ?? null,
            poderId:                     $validated['poderId'] ?? null,
            esferaId:                    $validated['esferaId'] ?? null,
            situacaoCompraId:            $validated['situacaoCompraId'] ?? null,
            modoDisputaId:               $validated['modoDisputaId'] ?? null,
            tipoInstrumentoConvocatorioCodigo: $validated['tipoInstrumentoConvocatorioCodigo'] ?? null,
            fontesOrcamentarias:         $validated['fontesOrcamentarias'] ?? null,
            tipoData:                    $validated['tipoData'] ?? 'publicacao',
            cursor:                      $validated['cursor'] ?? null,
            limit:                       (int) ($validated['limit'] ?? 15),
        );
    }

    public function messages(): array
    {
        return [
            'dataInicial.required'                 => 'A data inicial é obrigatória.',
            'dataInicial.date_format'              => 'A data inicial deve ser no formato Ymd. Ex: 20260101.',
            'dataFinal.required'                   => 'A data final é obrigatória.',
            'dataFinal.date_format'                => 'A data final deve ser no formato Ymd. Ex: 20260815.',
            'codigoModalidadeContratacao.required' => 'O código da modalidade de contratação é obrigatório.',
            'codigoModalidadeContratacao.integer'  => 'O código da modalidade de contratação deve ser um número inteiro.',
            'uf.max'                               => 'A UF deve ter no máximo 2 caracteres.',
            'codigoMunicipioIbge.integer'          => 'O código do município IBGE deve ser um número inteiro.',
            'limit.min'                            => 'O limite mínimo por página é 1.',
            'limit.max'                            => 'Por motivos de performance, o limite máximo por página é 30.',
        ];
    }
}
