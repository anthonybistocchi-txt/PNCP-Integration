<?php

namespace App\Resources\UserKeywords;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserKeywordResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'keyword'    => $this->keyword,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
