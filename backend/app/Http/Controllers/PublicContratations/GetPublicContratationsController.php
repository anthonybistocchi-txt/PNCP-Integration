<?php

namespace App\Http\Controllers\PublicContratations;

use App\Http\Controllers\Controller;
use App\Http\Requests\PublicContratations\GetPublicContratationsRequest;
use App\Resources\GetPublicContratationsResource;
use App\Services\PublicContratations\PublicContratationsService;

class GetPublicContratationsController extends Controller
{
    public function __construct(
        private PublicContratationsService $service
    ) {}

    public function get(GetPublicContratationsRequest $request)
    {
        // 1. O Request já fez a validação e nós pegamos o DTO limpo e tipado.
        $dto = $request->toDTO();

        // 2. Passamos o DTO para o Service que vai buscar no DB e engatilhar o Job.
        $data = $this->service->get($dto);

        // Se o banco local retornou vazio, sabemos que o Job de sincronização
        // foi enviado para o background. Retornamos um aviso amigável (Status 202 Accepted).
        if ($data->isEmpty()) {
            return response()->json([
                'message' => 'Sincronizando dados com o PNCP. Por favor, atualize em alguns instantes.',
                'data'    => []
            ], 202);
        }

        // 3. Retornamos o Resource que padroniza a saída
        return GetPublicContratationsResource::collection($data);
    }
}
