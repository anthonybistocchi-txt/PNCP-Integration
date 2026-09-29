<?php

namespace App\Models\UserContratationFilters;

use App\Models\Auth\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserContratationFilter extends Model
{
    protected $table = 'filtros_contratacao';

    /**
     * Dimensões de filtro suportadas. Usa os mesmos nomes de campo do DTO
     * de busca de contratações, para não precisar de tabela de tradução.
     */
    public const TYPES = [
        'uf',
        'codigoModalidadeContratacao',
        'poderId',
        'esferaId',
        'situacaoCompraId',
        'modoDisputaId',
        'tipoInstrumentoConvocatorioCodigo',
        'fontesOrcamentarias',
    ];

    protected $fillable = [
        'user_id',
        'filter_type',
        'value',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
