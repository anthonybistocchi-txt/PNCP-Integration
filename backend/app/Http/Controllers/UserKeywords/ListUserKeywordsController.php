<?php

namespace App\Http\Controllers\UserKeywords;

use App\Actions\UserKeywords\ListUserKeywordsAction;
use App\Http\Controllers\Controller;
use App\Resources\UserKeywordResource;
use Illuminate\Support\Facades\Auth;

class ListUserKeywordsController extends Controller
{
    public function __construct(
        private ListUserKeywordsAction $action
    ) {}

    public function get()
    {
        $keywords = $this->action->execute(Auth::id());

        return response()->json([
            'data' => UserKeywordResource::collection($keywords),
        ]);
    }
}
