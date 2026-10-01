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

    /**
     * Agrupa as palavras-chave do usuário por tipo (include/exclude), pronto
     * para ser usado na montagem da busca personalizada de contratações.
     *
     * @return array{include: list<string>, exclude: list<string>}
     */
    public function listGroupedByType(int $userId): array
    {
        $userKeywords = $this->repository->listByUserId($userId);

        $keywordsGroupedByType = [
            UserKeyword::TYPE_INCLUDE => [],
            UserKeyword::TYPE_EXCLUDE => [],
        ];

        foreach ($userKeywords as $userKeyword)
        {
            $keywordsGroupedByType[$userKeyword->type][] = $userKeyword->keyword;
        }

        return $keywordsGroupedByType;
    }

    public function saveOrUpdate(SaveUserKeywordDTO $keywordToSave): UserKeyword|Collection
    {
        if ($keywordToSave->id)
        {
            $existingKeyword = $this->repository->findByKeyword(
                $keywordToSave->userId,
                $keywordToSave->keywords[0],
                $keywordToSave->type
            );

            if ($existingKeyword && $existingKeyword->id !== $keywordToSave->id)
            {
                throw new ResourceConflictException("Esta palavra-chave já está cadastrada para o usuário.");
            }

            return $this->repository->saveOrUpdate($keywordToSave);
        }

        return $this->repository->upsertMany(
            $keywordToSave->userId,
            $keywordToSave->keywords,
            $keywordToSave->type
        );
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
