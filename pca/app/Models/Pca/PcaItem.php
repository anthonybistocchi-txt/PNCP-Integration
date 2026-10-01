<?php

namespace App\Models\Pca;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PcaItem extends Model
{
    protected $table = 'itens_pca';

    protected $fillable = [
        'plano_pca_id',
        'numero_item',
        'categoria_item_pca',
        'categoria_item_pca_nome',
        'catalogo_id',
        'classificacao_catalogo_id',
        'classificacao_superior_codigo',
        'classificacao_superior_nome',
        'pdm_codigo',
        'pdm_nome',
        'codigo_item',
        'descricao_item',
        'unidade_fornecimento',
        'quantidade',
        'valor_unitario',
        'valor_total',
        'valor_orcamento_exercicio',
        'data_desejada',
        'unidade_requisitante',
        'codigo_unidade_requisitante',
        'grupo_contratacao_codigo',
        'grupo_contratacao_nome',
        'data_publicacao_pncp',
        'data_inclusao',
        'data_atualizacao',
    ];

    protected $casts = [
        'quantidade'                => 'decimal:4',
        'valor_unitario'            => 'decimal:2',
        'valor_total'               => 'decimal:2',
        'valor_orcamento_exercicio' => 'decimal:2',
        'data_desejada'             => 'date',
        'data_publicacao_pncp'      => 'date',
        'data_inclusao'             => 'datetime',
        'data_atualizacao'          => 'datetime',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(PcaPlan::class, 'plano_pca_id');
    }
}
