<?php

namespace App\Models\Pca;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PcaPlan extends Model
{
    protected $table = 'planos_pca';

    protected $fillable = [
        'cnpj_orgao',
        'ano_pca',
        'sequencial_pca',
        'codigo_unidade',
        'nome_unidade',
        'situacao_pca',
        'data_publicacao_pncp',
        'data_inclusao',
        'data_atualizacao',
        'payload_completo',
        'itens_sincronizados_em',
        'itens_total_sincronizado',
    ];

    protected $casts = [
        'data_publicacao_pncp'     => 'date',
        'data_inclusao'            => 'datetime',
        'data_atualizacao'         => 'datetime',
        'payload_completo'         => 'object',
        'itens_sincronizados_em'   => 'datetime',
        'itens_total_sincronizado' => 'integer',
    ];

    public function itens(): HasMany
    {
        return $this->hasMany(PcaItem::class, 'plano_pca_id');
    }
}
