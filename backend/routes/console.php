<?php

use Illuminate\Support\Facades\Schedule;

// 04:30 e não 03:00: às 3h o pool de conexões do PNCP satura (todo mundo
// agenda no mesmo horário) e as requisições voltam 500 por ~25 minutos.
Schedule::command('pncp:sync')->dailyAt('04:30');
