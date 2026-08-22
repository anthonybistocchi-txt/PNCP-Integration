<?php

namespace App\Services\UserKeywords;

use App\DTOs\UserKeywords\SaveUserKeywordDTO;
use App\Models\UserKeyword;
use App\Repositories\UserKeywordRepository;
use Exception;
use Illuminate\Database\Eloquent\Collection;

class UserKeywordService
{
    public function __construct(
        private UserKeywordRepository $repository
    ) {}

    public function getByUserId(int $userId): Collection
    {
        return $this->repository->getByUserId($userId);
    }

    public function saveOrUpdate(SaveUserKeywordDTO $dto): UserKeyword
    {
        if ($dto->id)
        {
            $keyword = $this->repository->findByKeyword($dto->userId, $dto->keyword);

            if ($keyword && $keyword->id !== $dto->id)
            {
                throw new Exception("Esta palavra-chave já está cadastrada para o usuário.", 409);
            }
        }

        return $this->repository->saveOrUpdate($dto);
    }

    public function delete(int $id, int $userId): void
    {
        $deleted = $this->repository->delete($id, $userId);

        if (!$deleted)
        {
            throw new Exception("Palavra-chave não encontrada ou não pertence ao usuário.", 404);
        }
    }
}
