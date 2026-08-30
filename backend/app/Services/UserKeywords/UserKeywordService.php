<?php

namespace App\Services\UserKeywords;

use App\DTOs\UserKeywords\SaveUserKeywordDTO;
use App\Models\UserKeywords\UserKeyword;
use App\Repositories\UserKeywords\UserKeywordRepository;
use App\Exceptions\Domain\ResourceConflictException;
use App\Exceptions\Domain\ResourceNotFoundException;
use Illuminate\Database\Eloquent\Collection;

class UserKeywordService
{
    public function __construct(
        private UserKeywordRepository $repository
    ) {}

    public function listByUserId(int $userId): Collection
    {
        return $this->repository->listByUserId($userId);
    }

    public function saveOrUpdate(SaveUserKeywordDTO $dto): UserKeyword
    {
        if ($dto->id)
        {
            $keyword = $this->repository->findByKeyword($dto->userId, $dto->keyword);

            if ($keyword && $keyword->id !== $dto->id)
            {
                throw new ResourceConflictException("Esta palavra-chave já está cadastrada para o usuário.");
            }
        }

        return $this->repository->saveOrUpdate($dto);
    }

    public function delete(int $id, int $userId): void
    {
        $deleted = $this->repository->delete($id, $userId);

        if (!$deleted)
        {
            throw new ResourceNotFoundException("Palavra-chave não encontrada ou não pertence ao usuário.");
        }
    }
}
