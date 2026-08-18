<?php

namespace App\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class GetPublicContratationsResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray($request)
    {
        // $this representa o Model PublicContratation
        $payload = $this->payload_completo;

        return [
            'numeroControle'         => $this->numero_controle_pncp,
            'processo'               => $payload->processo ?? null,
            'objetoCompra'           => $payload->objetoCompra ?? null,
            'valorTotalEstimado'     => $payload->valorTotalEstimado ?? null,
            'valorTotalHomologado'   => $payload->valorTotalHomologado ?? null,
            'detalhesDisputa' => [
                'modalidade'              => $payload->modalidadeNome ?? null,
                'modoDisputa'             => $payload->modoDisputaNome ?? null,
                'instrumentoConvocatorio' => $payload->tipoInstrumentoConvocatorioNome ?? null,
                'sistemaRegistroPrecos'   => $payload->srp ?? false,
                'situacao'                => $payload->situacaoCompraNome ?? null,
            ],
            'amparoLegal'            => $payload->amparoLegal->nome ?? null,
            'informacaoComplementar' => $payload->informacaoComplementar ?? null,
            'orgaoEntidade' => [
                'cnpj'        => $payload->orgaoEntidade->cnpj ?? null,
                'razaoSocial' => $payload->orgaoEntidade->razaoSocial ?? null,
            ],
            'unidade' => [
                'uf'        => $payload->unidadeOrgao->ufSigla ?? null,
                'municipio' => $payload->unidadeOrgao->municipioNome ?? null,
                'nome'      => $payload->unidadeOrgao->nomeUnidade ?? null,
            ],
            'datas' => [
                'publicacao'           => clone $this->data_publicacao_pncp,
                'aberturaProposta'     => $payload->dataAberturaProposta ?? null,
                'encerramentoProposta' => $payload->dataEncerramentoProposta ?? null,
            ],
            'documentos' => $this->documentos ?? [],
            'links' => [
                'sistemaOrigem'      => $payload->linkSistemaOrigem ?? null,
                'processoEletronico' => $payload->linkProcessoEletronico ?? null,
            ],
        ];
    }
}
