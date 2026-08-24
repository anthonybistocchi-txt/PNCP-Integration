<?php

namespace App\Resources\PublicContratations;

use Illuminate\Http\Resources\Json\JsonResource;

class ListPublicContratationsResource extends JsonResource
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
            'objetoCompra'           => $this->objeto_compra,
            'valorTotalEstimado'     => $this->valor_total_estimado, // Agora já vem formatado automaticamente pelo Cast
            'valorTotalHomologado'   => $payload->valorTotalHomologado ?? null,
            'detalhesDisputa' => [
                'modalidade'              => $payload->modalidadeNome ?? null,
                'modoDisputa'             => $payload->modoDisputaNome ?? null,
                'instrumentoConvocatorio' => $payload->tipoInstrumentoConvocatorioNome ?? null,
                'sistemaRegistroPrecos'   => $this->srp,
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
                'publicacao'           => $this->data_publicacao_pncp,
                'aberturaProposta'     => $this->data_abertura_proposta,
                'encerramentoProposta' => $this->data_encerramento_proposta,
            ],
            'documentos' => $this->documentos ?? [],
            'links' => [
                'sistemaOrigem'      => $payload->linkSistemaOrigem ?? null,
                'processoEletronico' => $payload->linkProcessoEletronico ?? null,
            ],
        ];
    }
}

