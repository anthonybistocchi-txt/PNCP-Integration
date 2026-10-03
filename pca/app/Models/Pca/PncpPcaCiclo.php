<?php

namespace App\Models\Pca;

use Illuminate\Database\Eloquent\Builder;
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

    /**
     * Ciclos de PLANO incompletos: abandonados, finished abaixo do anunciado,
     * ou started além da janela de retentativa do PCA (o job já venceu).
     *
     * Restrito a `sequencial_pca IS NOT NULL`: o ciclo de órgão (mestre) não
     * tem itens, então `itens_processados < total_anunciado` não faz sentido
     * para ele.
     */
    public function scopeEmGap(Builder $query, ?int $retryHours = null): Builder
    {
        $retryHours ??= (int) config('pncp.retry_until_hours.pca', 72);
        $limiteStarted = now()->subHours($retryHours);

        return $query->whereNotNull('sequencial_pca')
            ->where(function (Builder $q) use ($limiteStarted)
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

    /**
     * Ciclos de plano ainda vivos: started dentro da janela, ou seja, com
     * páginas que ainda podem estar na fila.
     */
    public function scopeEmAndamento(Builder $query, ?int $retryHours = null): Builder
    {
        $retryHours ??= (int) config('pncp.retry_until_hours.pca', 72);

        return $query->whereNotNull('sequencial_pca')
            ->where('status', self::STATUS_STARTED)
            ->where('started_at', '>', now()->subHours($retryHours));
    }
}
