<?php

use Illuminate\Support\Facades\Schedule;

// 00:45 e não 03:00: às 3h o pool de conexões do PNCP satura (todo mundo
// agenda no mesmo horário) e as requisições voltam 500 por ~25 minutos.
Schedule::command('pncp:sync')->dailyAt('00:45');

// Drenagem contínua dos documentos pendentes (documents_fetched_at nulo), sem
// filtro de data. Deixou de ser "rede de segurança em duas rajadas" e passou a
// ser o caminho principal dos documentos.
//
// O que 15/09 mostrou sobre as duas rajadas (09h e 21h):
//
//  - cada uma abria ciclos de 6h com teto de 2.000 por modalidade. A 6 recebe
//    ~5.300 registros/dia, então 2 × 2.000 era teto menor que o fluxo: o
//    passivo crescia ~1.300/dia mesmo com 100% de sucesso;
//  - concentrar tudo em duas janelas fazia a fase de documentos competir pelo
//    mesmo limitador global da listagem e, nas duas vezes, derrubar as duas;
//  - um lote que pegasse o PNCP em pausa de 1h morria de retryUntil e só teria
//    nova chance 12h depois.
//
// A cada 20 min o desenho inverte: a janela é pequena, o teto cabe no
// orçamento do limitador (ver a conta em config/pncp.php), um lote abandonado
// é repescado na janela seguinte em vez de meio dia depois, e a carga fica
// espalhada no lugar de dois picos. Mantenha o intervalo daqui em sincronia
// com `pncp.pending_documents_interval_minutes`, que dimensiona o teto.
//
// cron explícito em vez de everyTwentyMinutes(): esse helper não existe nesta
// versão do Laravel (só every{Five,Ten,Fifteen,Thirty}Minutes).
Schedule::command('pncp:sync-documentos-pendentes')->cron('*/20 * * * *');

// Fotografa o passivo por modalidade e loga alerta (PNCP_PENDING_DOCS_BACKLOG_ALERTA)
// se alguma modalidade ficar acima do limiar. Não corrige nada sozinho — é o
// sinal de que a drenagem parou de acompanhar o fluxo daquela modalidade.
//
// Com a drenagem contínua a leitura das 23:45 fica mais dura de propósito: a
// última janela rodou há no máximo 20 min, então o passivo a essa hora deveria
// estar perto de zero, e não "ainda esvaziando" como nos ~3h que a rajada das
// 21h levava.
Schedule::command('pncp:monitorar-documentos-pendentes')->dailyAt('23:45');

// Gaps de listagem (pncp_listagem_ciclos): só loga WARNING se houver ciclo
// incompleto. Sempre exit 0 — não polui o schedule:run com stacktrace.
Schedule::command('pncp:listagem-gaps --for-schedule')->dailyAt('23:50');

// Backfill semanal de listagem. Não perseguimos 100% todo dia — o PNCP não
// depende da gente — então em vez de retry automático por gap, sábado e
// domingo (tráfego baixo, PNCP mais saudável) relistam os últimos 7 dias
// inteiros. O upsert é idempotente: reprocessar o que já convergiu só custa a
// chamada HTTP, e o que ficou incompleto durante a semana é pego aqui.
//
// Sábado e domingo, os dois: cada --days=7 conta a partir da data de execução,
// então as duas janelas se sobrepõem em ~5 dias — duas chances independentes
// de pegar o PNCP saudável, em vez de uma só.
//
// 06:00 e não 00:45 (mesmo horário do pncp:sync diário): o uniqueId não colide
// (--days=1 vs --days=7 são intervalos diferentes), mas os dois compartilham
// o throttle global e o breaker de listagem. Nos fins de semana o ciclo diário
// costuma fechar em minutos (em dia útil vimos ~01:55); 06:00 deixa folga
// ampla para a fila esvaziar antes do backfill de 7 dias.
//
// `pncp:listagem-gaps` (23:50, todo dia) é o termômetro: se depois de um fim
// de semana ainda aparecer ciclo `abandoned`/incompleto de sábado ou domingo,
// é sinal de que 7 dias de alguma modalidade grande (ex.: 6 — Pregão) não
// coube no retryUntil de 12h da listagem, e a janela precisa ser revista.
Schedule::command('pncp:sync --days=7')
    ->days([0, 6]) // domingo (0) e sábado (6)
    ->at('06:00');
