<?php

namespace App\Models\PublicContratations;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PncpListagemCiclo extends Model
{
    public const STATUS_STARTED = 'started';
    public const STATUS_FINISHED = 'finished';
    public const STATUS_EMPTY = 'empty';
    public const STATUS_ABANDONED = 'abandoned';

    protected $table = 'pncp_listagem_ciclos';

    protected $fillable = [
        'sync_id',
        'modalidade',
        'data_inicial',
        'data_final',
        'total_anunciado',
        'total_paginas',
        'items_processados',
        'paginas_completas',
        'status',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'modalidade'         => 'integer',
        'total_anunciado'    => 'integer',
        'total_paginas'      => 'integer',
        'items_processados'  => 'integer',
        'paginas_completas'  => 'integer',
        'started_at'         => 'datetime',
        'finished_at'        => 'datetime',
    ];

    /**
     * Ciclos incompletos: abandonados, finished abaixo do anunciado, ou
     * started além da janela de retry da listagem.
     */
    public function scopeEmGap(Builder $query, int $listagemRetryHours = 12): Builder
    {
        $limiteStarted = now()->subHours($listagemRetryHours);

        return $query->where(function (Builder $q) use ($limiteStarted) {
            $q->where('status', self::STATUS_ABANDONED)
                ->orWhere(function (Builder $q2) {
                    $q2->where('status', self::STATUS_FINISHED)
                        ->whereColumn('items_processados', '<', 'total_anunciado');
                })
                ->orWhere(function (Builder $q3) use ($limiteStarted) {
                    $q3->where('status', self::STATUS_STARTED)
                        ->where('started_at', '<=', $limiteStarted);
                });
        });
    }

    public function isGap(int $listagemRetryHours = 12): bool
    {
        if ($this->status === self::STATUS_ABANDONED) {
            return true;
        }

        if ($this->status === self::STATUS_FINISHED && $this->items_processados < $this->total_anunciado) {
            return true;
        }

        if ($this->status === self::STATUS_STARTED
            && $this->started_at !== null
            && $this->started_at->lte(now()->subHours($listagemRetryHours))
        ) {
            return true;
        }

        return false;
    }
}
