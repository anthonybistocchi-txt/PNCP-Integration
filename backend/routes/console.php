<?php

use Illuminate\Support\Facades\Schedule;

// 01:30 e não 03:00: às 3h o pool de conexões do PNCP satura (todo mundo
// agenda no mesmo horário) e as requisições voltam 500 por ~25 minutos.
Schedule::command('pncp:sync')->dailyAt('02:50');

// Varredura de documentos pendentes (documents_fetched_at nulo), sem filtro
// de data: rede de segurança para o que a Fase 2 não conseguiu buscar no dia
// (ex.: /arquivos fora do ar, como nos ~8h de 05-06/09). Não serve para cobrir
// o ciclo do dia — disso a Fase 2 já dá conta ao fim da listagem.
//
// Duas execuções, e não três. O uniqueId do job é por modalidade + dia e o
// uniqueFor é de 6h, então duas execuções separadas por menos que isso podem
// ser descartadas em silêncio se a primeira ainda estiver na fila — foi o caso
// do par 09h/15h, distantes exatamente 6h. 09h e 21h ficam 12h apart.
//
// Com o teto por execução, cada varredura despacha ~117 lotes (~3,2h de fila),
// dentro da janela de retryUntil mesmo dividindo a fila com a Fase 2. Duas
// ondas pequenas por dia drenam o passivo em ~metade do tempo de uma só, e dão
// duas chances independentes de pegar o PNCP saudável.
Schedule::command('pncp:sync-documentos-pendentes')->dailyAt('09:00');
Schedule::command('pncp:sync-documentos-pendentes')->dailyAt('21:00');

// Fotografa o passivo por modalidade e loga alerta (PNCP_PENDING_DOCS_BACKLOG_ALERTA)
// se alguma modalidade ficar acima do limiar mesmo após a varredura das 21h
// (que historicamente leva ~3h para esvaziar). Não corrige nada sozinho —
// é o sinal de que o teto (pncp.pending_documents_max_per_run) parou de dar
// conta do fluxo diário daquela modalidade. Ver decisão registrada em
// config/pncp.php sobre manter o Fase 1/Fase 2 desacoplado.
Schedule::command('pncp:monitorar-documentos-pendentes')->dailyAt('23:45');
