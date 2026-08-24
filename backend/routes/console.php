<?php

use Illuminate\Support\Facades\Schedule;

// Configura o Job de Sincronização para rodar todos os dias à meia-noite
Schedule::command('pncp:sync')->dailyAt('03:00');
// Schedule::command('pncp:sync')->dailyAt('12:00');
