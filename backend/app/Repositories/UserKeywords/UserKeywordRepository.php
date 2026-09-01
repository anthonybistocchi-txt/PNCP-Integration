<?php

namespace App\Repositories\UserKeywords;

use App\DTOs\UserKeywords\SaveUserKeywordDTO;
use App\Models\UserKeywords\UserKeyword;
use Illuminate\Database\Eloquent\Collection;

class UserKeywordRepository
{
    public function listByUserId(int $userId): Collection
    {
        return UserKeyword::where('user_id', $userId)
            ->orderBy('id')
            ->get();
    }

    public function saveOrUpdate(SaveUserKeywordDTO $dto): ?UserKeyword
    {
        return UserKeyword::updateOrCreate(
            [
                'user_id' => $dto->userId,
                'keyword' => $dto->keywords[0],
            ],
            [
                'id' => $dto->id
            ]
        );
    }

    /**
     * @param list<string> $keywords
     */
    public function upsertMany(int $userId, array $keywords): Collection
    {
        $now = now();

        $rows = array_map(static fn(string $keyword): array => [
            'user_id'    => $userId,
            'keyword'    => $keyword,
            'created_at' => $now,
            'updated_at' => $now,
        ], $keywords);

        UserKeyword::upsert(
            $rows,
            ['user_id', 'keyword'],
            ['updated_at']
        );

        return UserKeyword::where('user_id', $userId)
            ->whereIn('keyword', $keywords)
            ->orderBy('id')
            ->get();
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
