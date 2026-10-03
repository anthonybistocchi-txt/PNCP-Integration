<?php

use Illuminate\Support\Facades\Schedule;

// Allowlist de órgãos: zero HTTP, DISTINCT em contratacoes.
// Roda antes do discover de unidades e independente do sync de itens.
Schedule::command('pncp:import-orgaos')->dailyAt('12:00');

// Inventário de unidades (geo/enriquecimento). O sync de PCA não depende dessa
// tabela. Semanal e restrito a órgãos que já têm plano: órgão sem PCA não tem
// unidade para descobrir, e despachá-lo todo dia só gastava requisições do
// mesmo limitador das páginas (ver DiscoverPncpUnidadesCommand).
Schedule::command('pncp:discover-unidades')->weeklyOn(0, '12:10');

// Sync dos planos e itens, duas vezes por semana. Só baixa itens de plano novo
// ou alterado (marca d'água em planos_pca) e não reconsulta órgão sem PCA
// verificado há menos de `pncp.pca.recheck_empty_days`. O prazo de cada job é
// `pncp.retry_until_hours.pca` (72h): a fila é FIFO com limitador global, e a
// cauda de um lote cheio esperava mais que as 12h antigas e morria sem ser tentada.
Schedule::command('pncp:sync-pca')->days([1, 4])->at('00:00');

// Reprocessa só o que faltou (plano cujo ciclo morreu ou ficou incompleto),
// sem relistar órgãos. Barato: uma consulta ao banco + despacho limitado.
Schedule::command('pncp:sync-pca-faltantes')->dailyAt('06:00');
