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

    public function saveOrUpdate(SaveUserKeywordDTO $keywordToSave): ?UserKeyword
    {
        return UserKeyword::updateOrCreate(
            [
                'user_id' => $keywordToSave->userId,
                'keyword' => $keywordToSave->keywords[0],
                'type'    => $keywordToSave->type,
            ],
            [
                'id' => $keywordToSave->id
            ]
        );
    }

    /**
     * @param list<string> $keywords
     */
    public function upsertMany(int $userId, array $keywords, string $type): Collection
    {
        $now = now();

        $keywordRows = array_map(static fn(string $keyword): array => [
            'user_id'    => $userId,
            'keyword'    => $keyword,
            'type'       => $type,
            'created_at' => $now,
            'updated_at' => $now,
        ], $keywords);

        UserKeyword::upsert(
            $keywordRows,
            ['user_id', 'keyword', 'type'],
            ['updated_at']
        );

        return UserKeyword::where('user_id', $userId)
            ->where('type', $type)
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

    public function findByKeyword(int $userId, string $keyword, string $type): ?UserKeyword
    {
        return UserKeyword::where('user_id', $userId)
            ->where('keyword', $keyword)
            ->where('type', $type)
            ->first();
    }
}
