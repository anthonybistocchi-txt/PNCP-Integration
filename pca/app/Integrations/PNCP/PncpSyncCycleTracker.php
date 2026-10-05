<?php

namespace App\Integrations\PNCP;

use Illuminate\Support\Facades\Cache;

class PncpSyncCycleTracker
{
    /**
     * Folga somada à janela de retentativa do PCA: os contadores precisam
     * sobreviver ao último job que ainda pode concluir uma página. Se
     * expirarem antes, `markPageDone` recomeçaria o `done` do zero e o ciclo
     * jamais fecharia (ficaria `started` até virar gap por idade).
     */
    private const TTL_MARGIN_HOURS = 12;

    /**
     * TTL da trava de "modalidade ativa" (ver abaixo). Precisa cobrir o pior
     * caso de `retry_until_hours.documentos` (2h) com margem — senão a trava
     * poderia expirar por conta própria antes de o ciclo real terminar,
     * negando a proteção justamente na janela mais longa.
     *
     * Acompanhou a queda de 6h para 2h do retryUntil dos documentos. Manter
     * em 7h seria pior que inútil agora: com a drenagem rodando a cada 20
     * min, uma trava órfã de um ciclo já morto bloquearia ~21 janelas
     * seguidas da modalidade. O `finishModalidade` (inclusive via failed())
     * continua sendo o caminho normal de liberação; este TTL é só a rede.
     */
    private const MODALIDADE_ACTIVE_TTL_HOURS = 3;

    /**
     * Inicializa o contador do ciclo. Deve ser chamado pelo Job Mestre
     * imediatamente antes de enfileirar os operários.
     *
     * @param  int  $totalUnits  Páginas (contratacoes) ou lotes (documentos).
     * @param  int  $totalRecords  Total de registros esperados no ciclo (para o log de fim).
     */
    public function start(string $syncId, int $totalUnits, int $totalRecords = 0): void
    {
        $ttl = $this->ttl();

        Cache::put($this->doneKey($syncId), 0, $ttl);
        Cache::put($this->totalKey($syncId), $totalUnits, $ttl);
        Cache::put($this->recordsKey($syncId), $totalRecords, $ttl);
        Cache::put($this->itemsKey($syncId), 0, $ttl);
    }

    /**
     * O ciclo já tem contadores? Usado para retomar um ciclo sem zerar o
     * progresso das páginas que já terminaram.
     */
    public function isStarted(string $syncId): bool
    {
        return Cache::has($this->totalKey($syncId));
    }

    /**
     * Marca uma unidade (página/lote) como encerrada e acumula itens processados.
     * Não deve ser chamado em release/retry.
     *
     * Com `$unitKey` a contagem é idempotente: a mesma unidade só entra uma vez
     * no ciclo, não importa por quantos caminhos ela seja encerrada (sucesso,
     * `fail()` explícito seguido de `failed()`, reentrega do job). Sem isso, uma
     * página de falha permanente era contada duas vezes e o ciclo fechava com
     * páginas ainda por fazer.
     *
     * Quando a unidade já tinha sido contada e o ciclo está completo, o resumo
     * é devolvido de novo: quem o recebeu antes pode ter falhado ao gravá-lo
     * (a escrita do fechamento é idempotente).
     *
     * @return array{units_completed: int, items_processed: int, total_records: int}|null
     *         Resumo do ciclo quando a última unidade termina; null caso contrário.
     */
    public function markPageDone(string $syncId, int $itemsProcessed = 0, ?string $unitKey = null): ?array
    {
        if ($syncId === '')
        {
            return null;
        }

        if ($unitKey !== null && ! Cache::add($this->unitKey($syncId, $unitKey), 1, $this->ttl()))
        {
            return $this->summaryIfComplete($syncId);
        }

        if ($itemsProcessed > 0)
        {
            Cache::increment($this->itemsKey($syncId), $itemsProcessed);
        }

        $done  = (int) Cache::increment($this->doneKey($syncId));
        $total = (int) Cache::get($this->totalKey($syncId), 0);

        if ($total > 0 && $done === $total)
        {
            return $this->summary($syncId, $done);
        }

        return null;
    }

    /**
     * @return array{units_completed: int, items_processed: int, total_records: int}|null
     */
    private function summaryIfComplete(string $syncId): ?array
    {
        $done  = (int) Cache::get($this->doneKey($syncId), 0);
        $total = (int) Cache::get($this->totalKey($syncId), 0);

        return $total > 0 && $done >= $total ? $this->summary($syncId, $done) : null;
    }

    /**
     * @return array{units_completed: int, items_processed: int, total_records: int}
     */
    private function summary(string $syncId, int $done): array
    {
        return [
            'units_completed' => $done,
            'items_processed' => (int) Cache::get($this->itemsKey($syncId), 0),
            'total_records'   => (int) Cache::get($this->recordsKey($syncId), 0),
        ];
    }

    private function ttl(): \DateTimeInterface
    {
        return now()->addHours(
            (int) config('pncp.retry_until_hours.pca', 72) + self::TTL_MARGIN_HOURS
        );
    }

    private function unitKey(string $syncId, string $unitKey): string
    {
        return "pncp_sync:{$syncId}:unit:{$unitKey}";
    }

    /**
     * Trava por modalidade: impede que a Fase 2 (auto, ao fim da contratacoes) e
     * a varredura de pendentes (cron 09h/21h) despachem lotes de documentos
     * para a mesma modalidade ao mesmo tempo.
     *
     * Sem isso, as duas filas de `ProcessDocumentoPageJob` competem pelo
     * mesmo `documentos_sincronizados_em IS NULL`: cada uma redespacha registros que
     * a outra já está tentando buscar, dobrando as chamadas HTTP para o
     * mesmo `numero_controle_pncp` sem dobrar a capacidade real do throttle
     * global — só piora a vazão de ambas.
     *
     * `Cache::add` é atômico: só quem chega primeiro consegue a trava; quem
     * chega depois recebe `false` e deve desistir de despachar, sem esperar.
     *
     * @return bool true quando este syncId conseguiu a trava.
     */
    public function tryStartModalidade(int $modalidade, string $syncId): bool
    {
        return Cache::add(
            $this->modalidadeActiveKey($modalidade),
            $syncId,
            now()->addHours(self::MODALIDADE_ACTIVE_TTL_HOURS)
        );
    }

    /**
     * syncId do ciclo de documentos em andamento para a modalidade, se houver.
     * Útil só para logging/diagnóstico.
     */
    public function activeSyncId(int $modalidade): ?string
    {
        return Cache::get($this->modalidadeActiveKey($modalidade));
    }

    /**
     * Libera a trava ao fim do ciclo (última unidade concluída, sucesso ou
     * falha permanente). Só libera se o syncId ainda bater — evita que um
     * ciclo antigo (ex.: um lote atrasado que finalmente expira) apague a
     * trava de um ciclo mais novo que já a reconquistou.
     */
    public function finishModalidade(int $modalidade, string $syncId): void
    {
        $key = $this->modalidadeActiveKey($modalidade);

        if (Cache::get($key) === $syncId)
        {
            Cache::forget($key);
        }
    }

    private function modalidadeActiveKey(int $modalidade): string
    {
        return "pncp_sync:modalidade_active:{$modalidade}";
    }

    private function doneKey(string $syncId): string
    {
        return "pncp_sync:{$syncId}:done";
    }

    private function totalKey(string $syncId): string
    {
        return "pncp_sync:{$syncId}:total";
    }

    private function recordsKey(string $syncId): string
    {
        return "pncp_sync:{$syncId}:records";
    }

    private function itemsKey(string $syncId): string
    {
        return "pncp_sync:{$syncId}:items";
    }
}
