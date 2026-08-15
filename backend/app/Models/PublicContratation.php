<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PublicContratation extends Model
{
    // A chave primária não é um ID auto increment e é uma string
    protected $primaryKey = 'numero_controle_pncp';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'numero_controle_pncp',
        'orgao_cnpj',
        'ano_compra',
        'data_publicacao_pncp',
        'data_atualizacao_global',
        'uf',
        'codigo_municipio_ibge',
        'codigo_modalidade_contratacao',
        'payload_completo',
    ];

    // Transforma o JSON do banco de volta para um objeto PHP magicamente
    protected $casts = [
        'data_publicacao_pncp' => 'datetime',
        'data_atualizacao_global' => 'datetime',
        'payload_completo' => 'object', // ou 'array' se preferir trabalhar com arrays assoc
    ];
}
