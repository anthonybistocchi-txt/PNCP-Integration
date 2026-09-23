<?php

namespace App\Models\Pca;

use Illuminate\Database\Eloquent\Model;

class PncpPcaCiclo extends Model
{
    protected $table = 'pncp_pca_ciclos';

    protected $fillable = [
        'sync_id',
        'cnpj_orgao',
        'ano_pca',
        'sequencial_pca',
        'total_anunciado',
        'total_paginas',
        'items_processados',
        'paginas_completas',
        'status',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];
}
