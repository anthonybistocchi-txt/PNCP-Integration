<?php

namespace App\Resources\UserContratationFilters;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserContratationFilterResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'filter_type' => $this->filter_type,
            'value'       => $this->value,
            'created_at'  => $this->created_at,
            'updated_at'  => $this->updated_at,
        ];
    }
}
