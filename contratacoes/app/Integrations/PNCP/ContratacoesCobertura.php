<?php

namespace App\Integrations\PNCP;

use App\Models\Contratacoes\CicloContratacao;
use App\Models\Contratacoes\CoberturaContratacao;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Dias de listagem que o cron deve ter buscado.
 *
 * O reconciliador só enxerga linha em ciclos_contratacao. Se o comando nem
 * chegou a gravar a modalidade, não havia linha. Aqui o dia fica pendente
 * desde a primeira execução, e qualquer buraco até hoje volta na próxima.
 */
class ContratacoesCobertura
{
    /**
     * Intervalos diários (ontem→dia) ainda sem ciclo fechado.
     *
     * @param  list<int>  $modalidades
     * @return list<array{modalidade: int, data_inicial: string, data_final: string}>
     */
    public function intervalosEmAberto(array $modalidades): array
    {
        $abertos = [];

        foreach ($modalidades as $modalidade)
        {
            foreach ($this->diasEmAberto($modalidade) as $dia)
            {
                $abertos[] = [
                    'modalidade'   => $modalidade,
                    'data_inicial' => $dia->copy()->subDay()->format('Ymd'),
                    'data_final'   => $dia->format('Ymd'),
                ];
            }
        }

        return $abertos;
    }

    /**
     * Janelas diárias que já fecharam, para buscar de novo.
     *
     * O total anunciado pelo PNCP pode coincidir com o que gravamos e mesmo
     * assim faltar registro. Uma alteração também só reentra pelo endpoint
     * de atualização. Rebuscar a janela recente cobre os dois.
     *
     * @param  list<int>  $modalidades
     * @return list<array{modalidade: int, data_inicial: string, data_final: string}>
     */
    public function intervalosParaRechecagem(array $modalidades): array
    {
        $dias = max(0, (int) config('pncp.contratacoes_recheck_days', 7));
        $hoje = now()->startOfDay();
        $abertos = [];

        for ($i = 0; $i < $dias; $i++)
        {
            $dia     = $hoje->copy()->subDays($i);
            $inicial = $dia->copy()->subDay()->format('Ymd');
            $final   = $dia->format('Ymd');

            foreach ($modalidades as $modalidade)
            {
                if (! $this->temSucesso($modalidade, $inicial, $final))
                {
                    continue;
                }

                $abertos[] = [
                    'modalidade'   => $modalidade,
                    'data_inicial' => $inicial,
                    'data_final'   => $final,
                ];
            }
        }

        return $abertos;
    }

    public function temSucesso(int $modalidade, string $dataInicial, string $dataFinal): bool
    {
        return CicloContratacao::query()
            ->where('modalidade', $modalidade)
            ->where('data_inicial', $dataInicial)
            ->where('data_final', $dataFinal)
            ->where(function ($query)
            {
                $query->where('status', CicloContratacao::STATUS_EMPTY)
                    ->orWhere(function ($finished)
                    {
                        $finished->where('status', CicloContratacao::STATUS_FINISHED)
                            ->where('total_anunciado', '>', 0)
                            ->whereColumn('itens_processados', '>=', 'total_anunciado');
                    });
            })
            ->exists();
    }

    /**
     * @return list<CarbonInterface>
     */
    private function diasEmAberto(int $modalidade): array
    {
        $hoje    = now()->copy()->startOfDay();
        $recheck = max(1, (int) config('pncp.contratacoes_recheck_days', 7));
        $row     = CoberturaContratacao::query()->find($modalidade);

        if ($row === null)
        {
            $desde = $hoje->copy()->subDays($recheck - 1);

            $row = CoberturaContratacao::query()->create([
                'modalidade'  => $modalidade,
                'desde'       => $desde->format('Ymd'),
                'coberto_ate' => null,
            ]);
        }

        $inicio = $row->coberto_ate
            ? Carbon::createFromFormat('Ymd', $row->coberto_ate)->startOfDay()->addDay()
            : Carbon::createFromFormat('Ymd', $row->desde)->startOfDay();

        if ($inicio->gt($hoje))
        {
            return [];
        }

        $dias    = [];
        $cursor  = $row->coberto_ate;
        $avanca  = true;
        $retryH  = (int) config('pncp.retry_until_hours.contratacoes', 12);

        for ($dia = $inicio->copy(); $dia->lte($hoje); $dia->addDay())
        {
            $inicial = $dia->copy()->subDay()->format('Ymd');
            $final   = $dia->format('Ymd');

            if ($this->temSucesso($modalidade, $inicial, $final))
            {
                if ($avanca)
                {
                    $cursor = $final;
                }

                continue;
            }

            $avanca = false;

            if ($this->emAndamento($modalidade, $inicial, $final, $retryH))
            {
                continue;
            }

            $dias[] = $dia->copy();
        }

        if ($cursor !== $row->coberto_ate)
        {
            $row->coberto_ate = $cursor;
            $row->save();
        }

        return $dias;
    }

    private function emAndamento(int $modalidade, string $dataInicial, string $dataFinal, int $retryHours): bool
    {
        return CicloContratacao::query()
            ->where('modalidade', $modalidade)
            ->where('data_inicial', $dataInicial)
            ->where('data_final', $dataFinal)
            ->where('status', CicloContratacao::STATUS_STARTED)
            ->where('started_at', '>', now()->subHours($retryHours))
            ->where(function ($query)
            {
                $query->where('total_anunciado', '>', 0)
                    ->orWhere('total_paginas', '>', 0);
            })
            ->exists();
    }
}
