<?php

namespace App\DTOs\Paginate;

use Illuminate\Support\Collection;

readonly class PaginatedResultDTO
{
    public function __construct(
        public Collection $data,
        public int $page,
        public int $count_page,
        public int $count,
        public ?string $next_cursor,
        public bool $has_more,
    )
    {
    }
}
