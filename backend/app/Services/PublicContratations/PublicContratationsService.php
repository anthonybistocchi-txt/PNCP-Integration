<?php

namespace App\Services\PublicContratations;

use App\DTOs\PublicContratations\GetPublicContratationsDTO;
use App\Jobs\PublicContratations\SyncPncpDataJob;
use App\Repositories\PublicContratationRepository;
use Illuminate\Database\Eloquent\Collection;

class PublicContratationsService
{
    public function __construct(
        private PublicContratationRepository $repository
    ) {}

    /**
     * Ponto de entrada do serviço: busca no banco local e engatilha o job de sincronização.
     */
    public function get(GetPublicContratationsDTO $dto): Collection
    {
        // 1. O Banco Local é a ÚNICA fonte de verdade para leitura.
        // Toda a alimentação do banco agora é feita pelo Scheduler/Console Command no background.
        return $this->repository->getByFilters($dto);
    }
}
