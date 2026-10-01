<?php

namespace App\DTOs\Pca;

final class PcaItemData
{
    public function __construct(
        public readonly int $pcaPlanId,
        public readonly int $numeroItem,
        public readonly ?int $categoriaItemPca = null,
        public readonly ?string $categoriaItemPcaNome = null,
        public readonly ?int $catalogoId = null,
        public readonly ?int $classificacaoCatalogoId = null,
        public readonly ?string $classificacaoSuperiorCodigo = null,
        public readonly ?string $classificacaoSuperiorNome = null,
        public readonly ?string $pdmCodigo = null,
        public readonly ?string $pdmNome = null,
        public readonly ?string $codigoItem = null,
        public readonly ?string $descricaoItem = null,
        public readonly ?string $unidadeFornecimento = null,
        public readonly ?float $quantidade = null,
        public readonly ?float $valorUnitario = null,
        public readonly ?float $valorTotal = null,
        public readonly ?float $valorOrcamentoExercicio = null,
        public readonly ?string $dataDesejada = null,
        public readonly ?string $unidadeRequisitante = null,
        public readonly ?string $codigoUnidadeRequisitante = null,
        public readonly ?string $grupoContratacaoCodigo = null,
        public readonly ?string $grupoContratacaoNome = null,
        public readonly ?string $dataPublicacaoPncp = null,
        public readonly ?string $dataInclusao = null,
        public readonly ?string $dataAtualizacao = null,
    ) {}

    public function toArray(): array
    {
        return [
            'plano_pca_id'                  => $this->pcaPlanId,
            'numero_item'                   => $this->numeroItem,
            'categoria_item_pca'            => $this->categoriaItemPca,
            'categoria_item_pca_nome'       => $this->categoriaItemPcaNome,
            'catalogo_id'                   => $this->catalogoId,
            'classificacao_catalogo_id'     => $this->classificacaoCatalogoId,
            'classificacao_superior_codigo' => $this->classificacaoSuperiorCodigo,
            'classificacao_superior_nome'   => $this->classificacaoSuperiorNome,
            'pdm_codigo'                    => $this->pdmCodigo,
            'pdm_nome'                      => $this->pdmNome,
            'codigo_item'                   => $this->codigoItem,
            'descricao_item'                => $this->descricaoItem,
            'unidade_fornecimento'          => $this->unidadeFornecimento,
            'quantidade'                    => $this->quantidade,
            'valor_unitario'                => $this->valorUnitario,
            'valor_total'                   => $this->valorTotal,
            'valor_orcamento_exercicio'     => $this->valorOrcamentoExercicio,
            'data_desejada'                 => $this->dataDesejada,
            'unidade_requisitante'          => $this->unidadeRequisitante,
            'codigo_unidade_requisitante'   => $this->codigoUnidadeRequisitante,
            'grupo_contratacao_codigo'      => $this->grupoContratacaoCodigo,
            'grupo_contratacao_nome'        => $this->grupoContratacaoNome,
            'data_publicacao_pncp'          => $this->dataPublicacaoPncp,
            'data_inclusao'                 => $this->dataInclusao,
            'data_atualizacao'              => $this->dataAtualizacao,
        ];
    }
}
