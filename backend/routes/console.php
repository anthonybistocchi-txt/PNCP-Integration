<?php

use Illuminate\Support\Facades\Schedule;

// 04:30 e não 03:00: às 3h o pool de conexões do PNCP satura (todo mundo
// agenda no mesmo horário) e as requisições voltam 500 por ~25 minutos.
Schedule::command('pncp:sync')->dailyAt('01:30');

// Varredura de documentos pendentes (documents_fetched_at nulo), sem filtro
// de data — cobre o que a Fase 2 automática (presa ao intervalo ontem→hoje
// do ciclo de listagem) não consegue mais alcançar depois de ~2 dias. Em
// 05-06/09 o /arquivos ficou ~8h sem uma única sondagem bem-sucedida; sem
// esta varredura esses registros ficariam com documentos nulos para sempre.
// Horários espalhados e fora do pico das 04:30 para dar chances
// independentes ao longo do dia de o PNCP estar saudável.
Schedule::command('pncp:sync-documentos-pendentes')->dailyAt('09:00');
Schedule::command('pncp:sync-documentos-pendentes')->dailyAt('15:00');
Schedule::command('pncp:sync-documentos-pendentes')->dailyAt('21:00');
