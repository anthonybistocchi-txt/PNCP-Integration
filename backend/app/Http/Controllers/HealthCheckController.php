<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class HealthCheckController extends Controller
{
    /**
     * Verifica a saúde dos serviços críticos da aplicação.
     *
     * Endpoint público (sem autenticação) para uso por:
     * - Docker HEALTHCHECK
     * - Uptime Robot / Grafana / StatusPage
     * - Load Balancers que precisam saber se o pod está saudável
     *
     * Retorna 200 se tudo ok, 503 se qualquer serviço crítico estiver fora.
     */
    public function check(): JsonResponse
    {
        $database = $this->checkDatabase();
        $cache    = $this->checkCache();

        $allHealthy = $database === 'ok' && $cache === 'ok';

        return response()->json([
            'status'   => $allHealthy ? 'ok' : 'degraded',
            'database' => $database,
            'cache'    => $cache,
        ], $allHealthy ? 200 : 503);
    }

    private function checkDatabase(): string
    {
        try {
            DB::select('SELECT 1');
            return 'ok';
        } catch (\Throwable) {
            return 'unavailable';
        }
    }

    private function checkCache(): string
    {
        try {
            Cache::store('redis')->put('_health_check', 1, 5);
            return 'ok';
        } catch (\Throwable) {
            return 'unavailable';
        }
    }
}
