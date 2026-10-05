<?php

namespace App\Support\Pca;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Decide se os itens de um plano precisam ser baixados de novo.
 *
 * Usada pelo SyncPcaDataJob (com os dados frescos de `consolidado/unidades`)
 * e pelo pncp:sync-pca-faltantes (com os dados do último listing, já
 * guardados em planos_pca), para as duas decisões nunca divergirem.
 *
 * A referência é a marca d'água dos ITENS (`itens_sincronizados_em`), gravada
 * só quando o ciclo do plano fecha completo. A data do plano em planos_pca
 * não serve: o mestre a grava antes de baixar os itens.
 *
 * A `quantidade` da listagem NÃO entra na decisão. Ela difere do
 * `itens/quantidade` (a referência de completude do ciclo) em planos que nem
 * foram alterados (ex.: 26 contra 27), então usá-la deixaria esses planos
 * pendentes para sempre. Mudança real aparece em `dataAtualizacao`: nos
 * planos conferidos em 03/10, toda variação de contagem veio com data
 * posterior ao início do ciclo.
 */
final class PcaItemsFreshness
{
    /**
     * @param  CarbonInterface|null  $syncedAt     Início do último download completo (null = nunca).
     * @param  CarbonInterface|null  $apiUpdatedAt Última atualização do plano segundo o PNCP.
     * @param  int                   $refreshDays  Idade máxima da marca (0 desliga).
     */
    public static function isPending(
        ?CarbonInterface $syncedAt,
        ?CarbonInterface $apiUpdatedAt,
        int $refreshDays = 0,
    ): bool {
        if ($syncedAt === null)
        {
            return true;
        }

        if ($apiUpdatedAt !== null && $apiUpdatedAt->greaterThan($syncedAt))
        {
            return true;
        }

        if ($refreshDays > 0 && $syncedAt->lessThan(now()->subDays($refreshDays)))
        {
            return true;
        }

        return false;
    }

    /**
     * O PNCP devolve `dataAtualizacao` (da unidade) e `dataAtualizacaoGlobalPCA`
     * (do PCA inteiro) sem fuso, em horário de Brasília. Vale a mais recente.
     *
     * Aceita string (payload do PNCP) ou data já convertida (coluna do banco),
     * para o mestre e o sync-pca-faltantes usarem exatamente a mesma regra.
     */
    public static function latestUpdate(CarbonInterface|string|null ...$dates): ?CarbonInterface
    {
        $latest = null;

        foreach ($dates as $raw)
        {
            if ($raw === null || (is_string($raw) && trim($raw) === ''))
            {
                continue;
            }

            try
            {
                $parsed = $raw instanceof CarbonInterface
                    ? $raw
                    : Carbon::parse($raw, config('app.timezone'));
            }
            catch (\Throwable)
            {
                continue;
            }

            if ($latest === null || $parsed->greaterThan($latest))
            {
                $latest = $parsed;
            }
        }

        return $latest;
    }
}
