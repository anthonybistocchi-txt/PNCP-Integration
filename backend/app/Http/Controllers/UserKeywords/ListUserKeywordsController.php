<?php

namespace App\Http\Controllers\UserKeywords;

use App\Actions\UserKeywords\ListUserKeywordsAction;
use App\Http\Controllers\Controller;
use App\Resources\UserKeywords\UserKeywordResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class ListUserKeywordsController extends Controller
{
    public function __construct(
        private ListUserKeywordsAction $action
    ) {}

    public function listByUserId(): JsonResponse
    {
        $keywords = $this->action->listByUserId(Auth::id());

        return response()->json([
            'data' => UserKeywordResource::collection($keywords),
        ]);
    }
}
