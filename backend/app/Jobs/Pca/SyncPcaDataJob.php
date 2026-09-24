<?php

namespace App\Jobs\Pca;

use App\Actions\Pca\SyncPcaPlanAction;
use App\DTOs\Pca\PcaPlanData;
use App\DTOs\Pca\PcaSyncParamsDTO;
use App\Integrations\PNCP\PncpCircuitBreaker;
use App\Integrations\PNCP\PncpClient;
use App\Models\Pca\PncpPcaCiclo;
use App\Support\ErrorHandling\PncpFailureHandler;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SyncPcaDataJob implements ShouldQueue, ShouldBeUnique
{
    public $timeout = 300;
    public $backoff = [120, 300, 600];
    public int $uniqueFor = 43200;

    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected string $syncId = '';

    public function __construct(
        protected PcaSyncParamsDTO $dto
    ) {}

    public function uniqueId(): string
    {
        return "{$this->dto->ano}:{$this->dto->cnpj}";
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours((int) config('pncp.retry_until_hours.listagem', 12));
    }

    public function handle(
        PncpClient $client,
        PncpCircuitBreaker $circuitBreaker,
        PncpFailureHandler $failureHandler,
        SyncPcaPlanAction $upsertAction
    ): void {
        Log::withContext(['job_id' => $this->job ? $this->job->getJobId() : 'master-pca']);

        $startedAt = now();

        if ($failureHandler->shouldWait($this, 'Job Mestre PCA', PncpCircuitBreaker::SCOPE_PCA)) {
            return;
        }

        try {
            // `/consolidado` é um resumo do órgão (objeto único). A lista de
            // planos (sequencial + unidade) vem de `/consolidado/unidades`.
            $unidades = $client->getPcaUnidades($this->dto->cnpj, $this->dto->ano);
            $circuitBreaker->recordSuccess(PncpCircuitBreaker::SCOPE_PCA);

            if ($unidades === []) {
                Log::info('PNCP_PCA_FINISHED_EMPTY', [
                    'cnpj' => $this->dto->cnpj,
                    'ano' => $this->dto->ano,
                ]);
                return;
            }

            $this->syncId = (string) Str::uuid();
            PncpPcaCiclo::create([
                'sync_id' => $this->syncId,
                'cnpj_orgao' => $this->dto->cnpj,
                'ano_pca' => $this->dto->ano,
                'status' => 'started',
                'started_at' => $startedAt,
            ]);

            $plans = [];
            foreach ($unidades as $data) {
                if (! is_object($data)) {
                    continue;
                }

                $sequencial = (int) ($data->sequencialPca ?? 0);
                if ($sequencial <= 0) {
                    continue;
                }

                $plans[] = new PcaPlanData(
                    cnpjOrgao: $this->dto->cnpj,
                    anoPca: $this->dto->ano,
                    sequencialPca: $sequencial,
                    codigoUnidade: $data->codigoUnidade ?? $data->unidadeOrgao->codigoUnidade ?? null,
                    nomeUnidade: $data->nomeUnidade ?? $data->unidadeOrgao->nomeUnidade ?? null,
                    situacaoPca: $data->situacaoPca ?? null,
                    dataPublicacaoPncp: $data->dataPublicacaoPncp ?? null,
                    dataInclusao: $data->dataInclusao ?? null,
                    dataAtualizacao: $data->dataAtualizacao ?? null,
                    payloadCompleto: $data
                );
            }

            if ($plans === []) {
                PncpPcaCiclo::where('sync_id', $this->syncId)->update([
                    'status' => 'empty',
                    'finished_at' => now(),
                ]);

                Log::info('PNCP_PCA_FINISHED_EMPTY', [
                    'cnpj' => $this->dto->cnpj,
                    'ano' => $this->dto->ano,
                    'reason' => 'unidades sem sequencialPca',
                ]);

                return;
            }

            $upsertAction->handle($plans);

            foreach ($plans as $plan) {
                SyncPcaItemsJob::dispatch(
                    $this->dto->cnpj,
                    $this->dto->ano,
                    $plan->sequencialPca
                );
            }

        } catch (\Throwable $error) {
            $failureHandler->handle($error, $this, PncpCircuitBreaker::SCOPE_PCA, [
                'action' => 'SyncPcaDataJob',
                'attempt' => $this->attempts(),
                'cnpj' => $this->dto->cnpj,
                'ano' => $this->dto->ano,
            ]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        if ($this->syncId === '') {
            return;
        }

        PncpPcaCiclo::where('sync_id', $this->syncId)->update([
            'status' => 'abandoned',
            'finished_at' => now(),
        ]);
    }
}
