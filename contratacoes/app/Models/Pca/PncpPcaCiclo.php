<?php

namespace App\Models\Pca;

use Illuminate\Database\Eloquent\Model;

class PncpPcaCiclo extends Model
{
    public const STATUS_STARTED = 'started';
    public const STATUS_FINISHED = 'finished';
    public const STATUS_EMPTY = 'empty';
    public const STATUS_ABANDONED = 'abandoned';

    protected $table = 'ciclos_pca';

    protected $fillable = [
        'sync_id',
        'cnpj_orgao',
        'ano_pca',
        'sequencial_pca',
        'total_anunciado',
        'total_paginas',
        'itens_processados',
        'paginas_completas',
        'status',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'ano_pca'           => 'integer',
        'sequencial_pca'    => 'integer',
        'total_anunciado'   => 'integer',
        'total_paginas'     => 'integer',
        'itens_processados' => 'integer',
        'paginas_completas' => 'integer',
        'started_at'        => 'datetime',
        'finished_at'       => 'datetime',
    ];
}
