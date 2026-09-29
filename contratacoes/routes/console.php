<?php

use Illuminate\Support\Facades\Schedule;

// 00:45 e não 03:00: às 3h o pool de conexões do PNCP satura (todo mundo
// agenda no mesmo horário) e as requisições voltam 500 por ~25 minutos.
//
// contratacoes diária; gaps da semana são
// reprocessados por pncp:sync-contratacoes-faltantes (sáb/dom 02:00).
Schedule::command('pncp:sync-contratacoes')->dailyAt('00:45');

// Drenagem contínua dos documentos pendentes (documentos_sincronizados_em nulo), sem
// filtro de data. Deixou de ser "rede de segurança em duas rajadas" e passou a
// ser o caminho principal dos documentos.
//
// O que 15/09 mostrou sobre as duas rajadas (09h e 21h):
//
//  - cada uma abria ciclos de 6h com teto de 2.000 por modalidade. A 6 recebe
//    ~5.300 registros/dia, então 2 × 2.000 era teto menor que o fluxo: o
//    passivo crescia ~1.300/dia mesmo com 100% de sucesso;
//  - concentrar tudo em duas janelas fazia a fase de documentos competir pelo
//    mesmo limitador global da contratacoes e, nas duas vezes, derrubar as duas;
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

// Reprocessa só o que faltou: ciclos em gap em ciclos_contratacao
// (abandoned / finished abaixo do anunciado / started além do retryUntil).
// Substitui o antigo backfill cego `pncp:sync --days=7` — não relista a
// janela inteira, só dispara SyncContratacoesJob nos intervalos incompletos.
// Sábado e domingo (tráfego baixo), duas chances na semana.
Schedule::command('pncp:sync-contratacoes-faltantes')
    ->days([0, 6]) // domingo (0) e sábado (6)
    ->at('02:00');
