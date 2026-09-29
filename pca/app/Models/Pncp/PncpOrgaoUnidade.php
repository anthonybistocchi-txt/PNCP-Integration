<?php

namespace App\Models\Pncp;

use Illuminate\Database\Eloquent\Model;

class PncpOrgaoUnidade extends Model
{
    protected $table = 'unidades_orgao';

    protected $fillable = [
        'cnpj_orgao',
        'ano_pca',
        'codigo_unidade',
        'nome_unidade',
        'uf_sigla',
        'codigo_ibge',
        'nome_municipio',
        'poder',
        'esfera',
        'ativo',
        'payload_completo',
    ];

    protected $casts = [
        'ativo' => 'boolean',
        'payload_completo' => 'object',
    ];
}
