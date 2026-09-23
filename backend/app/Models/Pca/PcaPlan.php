<?php

namespace App\Models\Pca;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PcaPlan extends Model
{
    protected $table = 'pca_plans';

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
    ];

    protected $casts = [
        'data_publicacao_pncp' => 'date',
        'data_inclusao' => 'datetime',
        'data_atualizacao' => 'datetime',
        'payload_completo' => 'object',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(PcaItem::class, 'pca_plan_id');
    }
}
