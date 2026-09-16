<?php

namespace App\Integrations\PNCP;

use Illuminate\Support\Facades\Cache;

class PncpSyncCycleTracker
{
    private const TTL_HOURS = 24;

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
     * @param  int  $totalUnits  Páginas (listagem) ou lotes (documentos).
     * @param  int  $totalRecords  Total de registros esperados no ciclo (para o log de fim).
     */
    public function start(string $syncId, int $totalUnits, int $totalRecords = 0): void
    {
        $ttl = now()->addHours(self::TTL_HOURS);

        Cache::put($this->doneKey($syncId), 0, $ttl);
        Cache::put($this->totalKey($syncId), $totalUnits, $ttl);
        Cache::put($this->recordsKey($syncId), $totalRecords, $ttl);
        Cache::put($this->itemsKey($syncId), 0, $ttl);
    }

    /**
     * Marca uma unidade (página/lote) como encerrada e acumula itens processados.
     * Não deve ser chamado em release/retry.
     *
     * @return array{units_completed: int, items_processed: int, total_records: int}|null
     *         Resumo do ciclo quando a última unidade termina; null caso contrário.
     */
    public function markPageDone(string $syncId, int $itemsProcessed = 0): ?array
    {
        if ($syncId === '') {
            return null;
        }

        if ($itemsProcessed > 0) {
            Cache::increment($this->itemsKey($syncId), $itemsProcessed);
        }

        $done  = (int) Cache::increment($this->doneKey($syncId));
        $total = (int) Cache::get($this->totalKey($syncId), 0);

        if ($total > 0 && $done === $total) {
            return [
                'units_completed' => $done,
                'items_processed' => (int) Cache::get($this->itemsKey($syncId), 0),
                'total_records'   => (int) Cache::get($this->recordsKey($syncId), 0),
            ];
        }

        return null;
    }

    /**
     * Trava por modalidade: impede que a Fase 2 (auto, ao fim da listagem) e
     * a varredura de pendentes (cron 09h/21h) despachem lotes de documentos
     * para a mesma modalidade ao mesmo tempo.
     *
     * Sem isso, as duas filas de `ProcessPncpDocumentPageJob` competem pelo
     * mesmo `documents_fetched_at IS NULL`: cada uma redespacha registros que
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

        if (Cache::get($key) === $syncId) {
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
