<?php

namespace App\Repositories\Pca;

use App\Models\Pca\PcaPlan;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

class PcaPlanRepository
{
    /**
     * Marcas d'água dos itens, indexadas por sequencial do plano.
     *
     * Lidas antes do upsert: a decisão de baixar de novo é sobre o estado
     * anterior, e o upsert não toca nessas colunas.
     *
     * @return Collection<int|string, PcaPlan>
     */
    public function marcasPorSequencial(string $cnpj, int $ano): Collection
    {
        return PcaPlan::query()
            ->where('cnpj_orgao', $cnpj)
            ->where('ano_pca', $ano)
            ->get(['sequencial_pca', 'itens_sincronizados_em'])
            ->keyBy('sequencial_pca');
    }

    public function findByChave(string $cnpj, int $ano, int $sequencial): ?PcaPlan
    {
        return PcaPlan::query()
            ->where('cnpj_orgao', $cnpj)
            ->where('ano_pca', $ano)
            ->where('sequencial_pca', $sequencial)
            ->first();
    }

    /**
     * Grava a marca d'água dos itens deste plano.
     */
    public function markItensSincronizados(
        string $cnpj,
        int $ano,
        int $sequencial,
        DateTimeInterface $sincronizadosEm,
        int $total
    ): void {
        PcaPlan::query()
            ->where('cnpj_orgao', $cnpj)
            ->where('ano_pca', $ano)
            ->where('sequencial_pca', $sequencial)
            ->update([
                'itens_sincronizados_em'   => $sincronizadosEm,
                'itens_total_sincronizado' => $total,
            ]);
    }

    /**
     * Planos do ano, nunca sincronizados primeiro e depois os mais antigos.
     *
     * Com teto por execução, o passivo mais velho não fica para trás.
     *
     * Traz `data_atualizacao_global` (dataAtualizacaoGlobalPCA, lida do payload
     * da listagem) além de `data_atualizacao`: o SyncPcaDataJob decide pela mais
     * recente das duas, e o faltantes precisa usar a mesma regra.
     *
     * @return LazyCollection<int, PcaPlan>
     */
    public function cursorByAno(int $ano, ?string $cnpj = null): LazyCollection
    {
        return PcaPlan::query()
            ->where('ano_pca', $ano)
            ->when($cnpj, fn ($query, $cnpj) => $query->where('cnpj_orgao', $cnpj))
            ->orderByRaw('itens_sincronizados_em IS NOT NULL')
            ->orderBy('itens_sincronizados_em')
            ->orderBy('id')
            ->select([
                'id',
                'cnpj_orgao',
                'ano_pca',
                'sequencial_pca',
                'data_atualizacao',
                'itens_sincronizados_em',
                'payload_completo->dataAtualizacaoGlobalPCA as data_atualizacao_global',
            ])
            ->cursor();
    }
}
