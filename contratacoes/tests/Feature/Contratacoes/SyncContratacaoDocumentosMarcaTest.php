<?php

namespace Tests\Feature\Contratacoes;

use App\Actions\Contratacoes\SyncContratacaoAction;
use App\Models\Contratacoes\Contratacao;
use Tests\TestCase;

class SyncContratacaoDocumentosMarcaTest extends TestCase
{
    public function test_atualizacao_posterior_do_pncp_reabre_os_documentos(): void
    {
        $action = app(SyncContratacaoAction::class);
        $action->handle([$this->item('2026-10-01T10:00:00')]);

        Contratacao::query()->update([
            'documentos_sincronizados_em' => '2026-10-10 12:00:00',
        ]);

        $action->handle([$this->item('2026-10-20T08:00:00')]);

        $this->assertNull(Contratacao::query()->value('documentos_sincronizados_em'));
    }

    public function test_atualizacao_anterior_a_busca_mantem_os_documentos(): void
    {
        $action = app(SyncContratacaoAction::class);
        $action->handle([$this->item('2026-10-01T10:00:00')]);

        Contratacao::query()->update([
            'documentos_sincronizados_em' => '2026-10-20 12:00:00',
        ]);

        $action->handle([$this->item('2026-10-01T10:00:00')]);

        $this->assertNotNull(Contratacao::query()->value('documentos_sincronizados_em'));
    }

    private function item(string $atualizadoEm): object
    {
        return (object) [
            'numeroControlePNCP' => '12345678000199-1-1/2026',
            'anoCompra'          => 2026,
            'sequencialCompra'   => 1,
            'modalidadeId'       => 6,
            'dataPublicacaoPncp' => '2026-10-01T09:00:00',
            'dataAtualizacaoGlobal' => $atualizadoEm,
            'orgaoEntidade'      => (object) ['cnpj' => '12345678000199'],
            'unidadeOrgao'       => (object) ['ufSigla' => 'SP', 'codigoIbge' => 3550308],
        ];
    }
}
