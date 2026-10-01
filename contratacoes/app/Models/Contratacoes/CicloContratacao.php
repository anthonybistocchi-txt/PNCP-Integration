<?php

namespace App\Models\Contratacoes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class CicloContratacao extends Model
{
    public const STATUS_STARTED = 'started';
    public const STATUS_FINISHED = 'finished';
    public const STATUS_EMPTY = 'empty';
    public const STATUS_ABANDONED = 'abandoned';

    protected $table = 'ciclos_contratacao';

    protected $fillable = [
        'sync_id',
        'modalidade',
        'data_inicial',
        'data_final',
        'total_anunciado',
        'total_paginas',
        'itens_processados',
        'paginas_completas',
        'status',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'modalidade'        => 'integer',
        'total_anunciado'   => 'integer',
        'total_paginas'     => 'integer',
        'itens_processados' => 'integer',
        'paginas_completas' => 'integer',
        'started_at'        => 'datetime',
        'finished_at'       => 'datetime',
    ];

    /**
     * Ciclos incompletos: abandonados, finished abaixo do anunciado, ou
     * started além da janela de retry da contratacoes.
     */
    public function scopeEmGap(Builder $query, int $contratacoesRetryHours = 12): Builder
    {
        $limiteStarted = now()->subHours($contratacoesRetryHours);

        return $query->where(function (Builder $q) use ($limiteStarted)
        {
            $q->where('status', self::STATUS_ABANDONED)
                ->orWhere(function (Builder $q2)
                {
                    $q2->where('status', self::STATUS_FINISHED)
                        ->whereColumn('itens_processados', '<', 'total_anunciado');
                })
                ->orWhere(function (Builder $q3) use ($limiteStarted)
                {
                    $q3->where('status', self::STATUS_STARTED)
                        ->where('started_at', '<=', $limiteStarted);
                });
        });
    }

    public function isGap(int $contratacoesRetryHours = 12): bool
    {
        if ($this->status === self::STATUS_ABANDONED)
        {
            return true;
        }

        if ($this->status === self::STATUS_FINISHED && $this->itens_processados < $this->total_anunciado)
        {
            return true;
        }

        if ($this->status === self::STATUS_STARTED
            && $this->started_at !== null
            && $this->started_at->lte(now()->subHours($contratacoesRetryHours))
        )
        {
            return true;
        }

        return false;
    }
}
