<?php

namespace App\Repositories;

use App\DTOs\UserKeywords\SaveUserKeywordDTO;
use App\Models\UserKeyword;
use Illuminate\Database\Eloquent\Collection;

class UserKeywordRepository
{
    public function listByUserId(int $userId): Collection
    {
        return UserKeyword::where('user_id', $userId)
            ->orderBy('keyword')
            ->get();
    }

    public function saveOrUpdate(SaveUserKeywordDTO $dto): ?UserKeyword
    {
        return UserKeyword::updateOrCreate(
            [
                'user_id' => $dto->userId,
                'keyword' => $dto->keyword
            ],
            [
                'id' => $dto->id
            ]
        );
    }

    public function delete(int $id, int $userId): bool
    {
        return UserKeyword::where('id', $id)
            ->where('user_id', $userId)
            ->delete() > 0;
    }

    public function findByKeyword(int $userId, string $keyword): ?UserKeyword
    {
        return UserKeyword::where('user_id', $userId)
            ->where('keyword', $keyword)
            ->first();
    }
}
