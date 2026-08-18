<?php

namespace App\Integrations\PNCP;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PncpClient
{
    private string $baseUrlPublicacao = 'https://pncp.gov.br/api/consulta/v1/contratacoes/publicacao';
    private string $baseUrlAtualizacao = 'https://pncp.gov.br/api/consulta/v1/contratacoes/atualizacao';

    private string $baseUrlDocumentos = 'https://pncp.gov.br/api/pncp/v1/orgaos';

    /**
     * Busca licitações no PNCP com base nos filtros
     */
    public function getContratacoes(array $queryParams = [], bool $porAtualizacao = true): ?object
    {
        $url = $porAtualizacao ? $this->baseUrlAtualizacao : $this->baseUrlPublicacao;

        $response = Http::timeout(60)
            ->retry(3, 2000)
            ->withHeaders([
                'Accept' => 'application/json',
            ])
            ->get($url, $queryParams);

        if ($response->successful()) {
            return json_decode($response->body());
        }

        if ($response->status() === 429) {
            $response->throw();
        }

        Log::error("Falha ao integrar com PNCP. Status: " . $response->status() . " - " . $response->body());

        return null;
    }

    /**
     * Busca os arquivos/anexos (Editais, Termos) de uma contratação específica
     */
    public function getArquivos(string $cnpj, int $ano, int $sequencial): array
    {
        $response = Http::timeout(10)
            ->retry(2, 1000)
            ->withHeaders([
                'Accept' => 'application/json',
            ])
            ->get($this->baseUrlDocumentos . "/{$cnpj}/compras/{$ano}/{$sequencial}/arquivos");

        if ($response->successful()) {
            $arquivos = json_decode($response->body(), true);
            // Retorna array de arquivos ou vazio se não houver
            return is_array($arquivos) ? $arquivos : [];
        }

        if ($response->status() === 429) {
            $response->throw();
        }

        // Não vamos logar como erro crítico pois muitas compras não têm arquivos ainda.
        Log::warning("Não foi possível buscar arquivos da compra {$cnpj}/{$ano}/{$sequencial}. Status: " . $response->status());

        return [];
    }
}
