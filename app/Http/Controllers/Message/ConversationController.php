<?php

declare(strict_types=1);

namespace App\Http\Controllers\Message;

use App\Http\Controllers\Controller;
use App\Http\Requests\Message\ConversationListRequest;
use App\Http\Requests\Message\MarkConversationReadRequest;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use App\Repositories\Message\Queries\ConversationMessagesQuery;
use App\Repositories\Message\Queries\ConversationThreadsQuery;
use App\Repositories\Message\Queries\UnreadConversationCounter;
use App\Services\Message\Actions\MarkConversationReadAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;

class ConversationController extends Controller
{
    public function __construct(
        private readonly ConversationThreadsQuery $threads,
        private readonly UnreadConversationCounter $unread,
    ) {}

    public function show(int $userId, ConversationMessagesQuery $messages): AnonymousResourceCollection
    {
        return MessageResource::collection(
            $messages->paginate((int) Auth::id(), $userId)
        )->additional(['status' => true]);
    }

    public function index(ConversationListRequest $request): AnonymousResourceCollection
    {
        return ConversationResource::collection($this->paginate($request))->additional([
            'status' => true,
            'TotalUnreadConversationsCount' => $this->unread->forUser((int) Auth::id()),
        ]);
    }

    public function search(ConversationListRequest $request): AnonymousResourceCollection
    {
        return ConversationResource::collection($this->paginate($request))
            ->additional(['status' => true]);
    }

    public function markAsRead(MarkConversationReadRequest $request, MarkConversationReadAction $markRead): JsonResponse
    {
        $markRead->execute((int) Auth::id(), $request->partnerId());

        return response()->json(['status' => true]);
    }

    private function paginate(ConversationListRequest $request)
    {
        return $this->threads->paginate(
            (int) Auth::id(),
            $request->page(),
            $request->perPage(),
            $request->search()
        );
    }
}
