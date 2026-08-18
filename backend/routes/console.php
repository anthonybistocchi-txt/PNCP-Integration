<?php

use Illuminate\Support\Facades\Schedule;

// Configura o Job de Sincronização para rodar todos os dias à meia-noite
Schedule::command('pncp:sync')->dailyAt('02:00');
