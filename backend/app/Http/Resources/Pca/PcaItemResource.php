<?php

namespace App\Http\Resources\Pca;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PcaItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'orgao' => [
                'cnpj' => $this->cnpj_orgao,
                'codigo_unidade' => $this->codigo_unidade,
                'nome_unidade' => $this->nome_unidade,
            ],
            'ano_pca' => $this->ano_pca,
            'numero_item' => $this->numero_item,
            'categoria_item_pca' => $this->categoria_item_pca,
            'categoria_item_pca_nome' => $this->categoria_item_pca_nome,
            'codigo_item' => $this->codigo_item,
            'descricao_item' => $this->descricao_item,
            'unidade_fornecimento' => $this->unidade_fornecimento,
            'quantidade' => $this->quantidade ? (float) $this->quantidade : null,
            'valor_unitario' => $this->valor_unitario ? (float) $this->valor_unitario : null,
            'valor_total' => $this->valor_total ? (float) $this->valor_total : null,
            'valor_orcamento_exercicio' => $this->valor_orcamento_exercicio ? (float) $this->valor_orcamento_exercicio : null,
            'data_desejada' => $this->data_desejada ? $this->data_desejada->format('Y-m-d') : null,
            'unidade_requisitante' => $this->unidade_requisitante,
            'grupo_contratacao_nome' => $this->grupo_contratacao_nome,
            'data_publicacao_pncp' => $this->data_publicacao_pncp ? $this->data_publicacao_pncp->format('Y-m-d') : null,
            'data_atualizacao' => $this->data_atualizacao ? $this->data_atualizacao->toIso8601String() : null,
        ];
    }
}
