<?php

namespace App\Models\Pncp;

use Illuminate\Database\Eloquent\Model;

class PncpOrgaoUnidade extends Model
{
    protected $table = 'pncp_orgao_unidades';

    protected $fillable = [
        'cnpj_orgao',
        'codigo_unidade',
        'nome_unidade',
        'uf_sigla',
        'codigo_ibge',
        'nome_municipio',
        'poder_id',
        'esfera_id',
        'ativo',
        'payload_completo',
    ];

    protected $casts = [
        'ativo' => 'boolean',
        'payload_completo' => 'object',
    ];
}
