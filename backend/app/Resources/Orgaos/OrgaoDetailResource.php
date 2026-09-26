<?php

namespace App\Resources\Orgaos;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrgaoDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'cnpj' => $this->cnpj,
            'razao_social' => $this->razao_social,
            'poder' => $this->poder,
            'esfera' => $this->esfera,
            'ativo' => $this->ativo,
            'planos' => $this->plans->map(fn ($plan) => [
                'sequencial_pca' => $plan->sequencial_pca,
                'ano_pca' => $plan->ano_pca,
                'codigo_unidade' => $plan->codigo_unidade,
                'nome_unidade' => $plan->nome_unidade,
                'situacao_pca' => $plan->situacao_pca,
                'data_publicacao_pncp' => $plan->data_publicacao_pncp?->format('Y-m-d'),
                'itens' => $plan->items->map(fn ($item) => [
                    'numero_item' => $item->numero_item,
                    'categoria_item_pca' => $item->categoria_item_pca,
                    'categoria_item_pca_nome' => $item->categoria_item_pca_nome,
                    'codigo_item' => $item->codigo_item,
                    'descricao_item' => $item->descricao_item,
                    'unidade_fornecimento' => $item->unidade_fornecimento,
                    'quantidade' => $item->quantidade !== null ? (float) $item->quantidade : null,
                    'valor_unitario' => $item->valor_unitario !== null ? (float) $item->valor_unitario : null,
                    'valor_total' => $item->valor_total !== null ? (float) $item->valor_total : null,
                    'valor_orcamento_exercicio' => $item->valor_orcamento_exercicio !== null ? (float) $item->valor_orcamento_exercicio : null,
                    'data_desejada' => $item->data_desejada?->format('Y-m-d'),
                    'unidade_requisitante' => $item->unidade_requisitante,
                    'grupo_contratacao_nome' => $item->grupo_contratacao_nome,
                    'data_publicacao_pncp' => $item->data_publicacao_pncp?->format('Y-m-d'),
                ])->values(),
            ])->values(),
        ];
    }
}
