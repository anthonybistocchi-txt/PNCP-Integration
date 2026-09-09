<?php

namespace App\Models\PublicContratations;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;

class PublicContratation extends Model
{
    use HasFactory;
    
    // A chave primária não é um ID auto increment e é uma string
    protected $primaryKey = 'numero_controle_pncp';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'numero_controle_pncp',
        'orgao_cnpj',
        'ano_compra',
        'sequencial_compra',
        'data_publicacao_pncp',
        'data_atualizacao_global',
        'uf',
        'codigo_municipio_ibge',
        'codigo_modalidade_contratacao',
        'situacao_compra_id',
        'valor_total_estimado',
        'data_abertura_proposta',
        'data_encerramento_proposta',
        'srp',
        'objeto_compra',
        'payload_completo',
        'documentos',
        'documents_fetched_at',
    ];

    // Transforma o JSON do banco de volta para um objeto PHP magicamente
    protected $casts = [
        'data_publicacao_pncp'       => 'datetime',
        'data_atualizacao_global'    => 'datetime',
        'data_abertura_proposta'     => 'datetime',
        'data_encerramento_proposta' => 'datetime',
        'documents_fetched_at'       => 'datetime',
        'srp'                        => 'boolean',
        'payload_completo'           => 'object', // ou 'array' se preferir trabalhar com arrays assoc
        'documentos'                 => 'array',
    ];

    /**
     * Get/Set do valor total estimado para evitar problemas de ponto flutuante.
     * O banco salva em centavos (integer) e a aplicação lida com reais (float/decimal).
     */
    protected function valorTotalEstimado(): Attribute
    {
        return Attribute::make(
            get: fn(?int $value) => $value !== null ? $value / 100 : null,
            set: fn(float|int|null $value) => $value !== null ? (int) round($value * 100) : null,
        );
    }
}
