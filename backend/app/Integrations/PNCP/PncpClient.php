<?php

namespace App\Integrations\PNCP;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PncpClient
{
    private string $baseUrl = 'https://pncp.gov.br/api/consulta/v1/contratacoes/publicacao';

    /**
     * Busca licitações no PNCP com base nos filtros
     */
    public function getContratacoes(array $queryParams = []): ?object
    {
        $response = Http::timeout(60)
            ->retry(3, 2000)
            ->withHeaders([
                'Accept' => 'application/json',
            ])
            ->get($this->baseUrl, $queryParams);

        if ($response->successful()) {
            return json_decode($response->body());
        }

        Log::error("Falha ao integrar com PNCP. Status: " . $response->status() . " - " . $response->body());

        return null;
    }
}
