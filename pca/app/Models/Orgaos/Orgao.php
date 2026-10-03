<?php

namespace App\Models\Orgaos;

use App\Models\Pca\PcaPlan;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Orgao extends Model
{
    protected $table = 'orgaos';

    protected $fillable = [
        'cnpj',
        'razao_social',
        'poder',
        'esfera',
        'ativo',
        'importado_de',
        'pca_ano_verificado',
        'pca_verificado_em',
        'pca_tem_plano',
    ];

    protected $casts = [
        'ativo'              => 'boolean',
        'pca_tem_plano'      => 'boolean',
        'pca_ano_verificado' => 'integer',
        'pca_verificado_em'  => 'datetime',
    ];

    public function plans(): HasMany
    {
        return $this->hasMany(PcaPlan::class, 'cnpj_orgao', 'cnpj');
    }
}
