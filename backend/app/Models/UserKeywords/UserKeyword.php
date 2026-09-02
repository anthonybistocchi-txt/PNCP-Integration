<?php

namespace App\Models\UserKeywords;

use App\Models\Auth\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserKeyword extends Model
{
    public const TYPE_INCLUDE = 'include';
    public const TYPE_EXCLUDE = 'exclude';

    protected $fillable = [
        'user_id',
        'keyword',
        'type',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
