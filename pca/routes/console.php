<?php

use Illuminate\Support\Facades\Schedule;

// Allowlist de órgãos: zero HTTP, DISTINCT em contratacoes.
// Roda antes do discover de unidades e independente do sync de itens.
Schedule::command('pncp:import-orgaos')->dailyAt('12:00');

// Inventário de unidades. O sync de PCA não depende dessa tabela.
Schedule::command('pncp:discover-unidades')->dailyAt('12:10');

// Sync diário dos planos e itens. Fila e workers próprios, Redis próprio.
Schedule::command('pncp:sync-pca')->dailyAt('00:00');
