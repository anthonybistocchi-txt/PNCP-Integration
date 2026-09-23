<?php

namespace App\Models\Pncp;

use Illuminate\Database\Eloquent\Model;

class PncpOrgao extends Model
{
    protected $table = 'pncp_orgaos';

    protected $fillable = [
        'cnpj',
        'razao_social',
        'poder_id',
        'esfera_id',
        'ativo',
        'importado_de',
    ];

    protected $casts = [
        'ativo' => 'boolean',
    ];
}
