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
    ];

    protected $casts = [
        'ativo' => 'boolean',
    ];

    public function plans(): HasMany
    {
        return $this->hasMany(PcaPlan::class, 'cnpj_orgao', 'cnpj');
    }
}
