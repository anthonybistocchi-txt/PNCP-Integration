<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Preenche sequencial_compra (e, se nulos, ano_compra / orgao_cnpj) a partir
 * do payload_completo JSONB. Necessário porque a coluna sequencial_compra só
 * entrou em 04/09/2026: registros anteriores ficaram com a coluna nula mesmo
 * tendo o valor dentro do JSON — e sem ela a Fase 2 não consegue chamar
 * /arquivos.
 */
class BackfillSequencialCompraCommand extends Command
{
    protected $signature = 'pncp:backfill-sequencial
        {--dry-run : Só conta quantos seriam atualizados, sem gravar}
        {--chunk=5000 : Tamanho do lote por UPDATE}';

    protected $description = 'Preenche sequencial_compra a partir de payload_completo->>sequencialCompra';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $chunk  = max(100, (int) $this->option('chunk'));

        $elegiveis = (int) DB::table('public_contratations')
            ->whereNull('sequencial_compra')
            ->whereRaw("payload_completo->>'sequencialCompra' IS NOT NULL")
            ->whereRaw("payload_completo->>'sequencialCompra' <> ''")
            ->count();

        $semPayload = (int) DB::table('public_contratations')
            ->whereNull('sequencial_compra')
            ->where(function ($q) {
                $q->whereRaw("payload_completo->>'sequencialCompra' IS NULL")
                    ->orWhereRaw("payload_completo->>'sequencialCompra' = ''");
            })
            ->count();

        $this->info("Elegíveis (tem sequencialCompra no JSON): {$elegiveis}");
        $this->info("Sem sequencial no JSON (ficam de fora): {$semPayload}");

        if ($dryRun || $elegiveis === 0) {
            $this->warn($dryRun ? 'Dry-run: nenhuma linha alterada.' : 'Nada a atualizar.');
            return self::SUCCESS;
        }

        $atualizados = 0;

        // Postgres: UPDATE ... WHERE ctid IN (SELECT ... LIMIT) em loop,
        // evitando carregar 200k IDs na memória do PHP.
        do {
            $affected = DB::update("
                UPDATE public_contratations AS pc
                SET
                    sequencial_compra = (pc.payload_completo->>'sequencialCompra')::integer,
                    ano_compra = COALESCE(
                        pc.ano_compra,
                        NULLIF(pc.payload_completo->>'anoCompra', '')::integer
                    ),
                    orgao_cnpj = COALESCE(
                        pc.orgao_cnpj,
                        pc.payload_completo->'orgaoEntidade'->>'cnpj'
                    ),
                    updated_at = NOW()
                WHERE pc.numero_controle_pncp IN (
                    SELECT numero_controle_pncp
                    FROM public_contratations
                    WHERE sequencial_compra IS NULL
                      AND payload_completo->>'sequencialCompra' IS NOT NULL
                      AND payload_completo->>'sequencialCompra' <> ''
                    LIMIT ?
                )
            ", [$chunk]);

            $atualizados += $affected;
            $this->info("Lote: {$affected} (acumulado: {$atualizados}/{$elegiveis})");
        } while ($affected > 0);

        $this->info("Concluído. Linhas atualizadas: {$atualizados}");

        return self::SUCCESS;
    }
}
