<?php

namespace App\Models\Contratacoes;

use Illuminate\Database\Eloquent\Model;

class CoberturaContratacao extends Model
{
    protected $table = 'cobertura_contratacao';

    protected $primaryKey = 'modalidade';

    public $incrementing = false;

    protected $fillable = [
        'modalidade',
        'desde',
        'coberto_ate',
    ];

    protected $casts = [
        'modalidade' => 'integer',
    ];
}
