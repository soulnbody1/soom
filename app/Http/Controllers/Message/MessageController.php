<?php

namespace App\Http\Controllers\Message;

use App\Http\Controllers\Controller;
use App\Http\Requests\DeleteMessageRequest;
use App\Http\Requests\Message\ConversationListRequest;
use App\Http\Requests\StoreMessageRequest;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use App\Models\Message;
use App\Services\MessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class MessageController extends Controller
{
    protected MessageService $messageService;

    public function __construct(MessageService $messageService)
    {
        $this->messageService = $messageService;
    }
    // إرسال رسالة
    public function store(StoreMessageRequest $request): JsonResponse
    {
        try {
            $message = $this->messageService->sendMessage($request->validated());
            return response()->json([
                'status' => true,
                'message' => 'تم إرسال الرسالة بنجاح',
                'data' => new MessageResource($message->load('ad.images'))
            ]);
        } catch (\Exception $e) {
            Log::error('فشل في إرسال الرسالة: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status' => false,
                'message' => 'حدث خطأ أثناء إرسال الرسالة. حاول مرة أخرى لاحقًا.'
            ], 500); 
        }
    }
    // جلب المحادثة بين المستخدم الحالي والمستخدم الآخر
    public function getConversation($userId)
    {
        $messages = $this->messageService->getConversationWith((int) $userId);
        return MessageResource::collection($messages)->additional(['status' => true]);
    }

    public function getConversationsList(ConversationListRequest $request)
    {
        $conversations = $this->messageService->getUserConversations(
            $request->search(),
            $request->page(),
            $request->perPage()
        );

        return ConversationResource::collection($conversations)->additional([
            'status' => true,
            'TotalUnreadConversationsCount' => $this->messageService->getTotalUnreadConversationsCount((int) Auth::id()),
        ]);
    }

    public function markAsRead(Request $request)
    {
        $fromUser = $request->input('user_id');
        $myId = Auth::id();

        Message::where('sender_id', $fromUser)
            ->where('receiver_id', $myId)
            ->where('is_read', false)
            ->update(['is_read' => true]);
        return response()->json(['status' => true]);
    }

    public function delete(DeleteMessageRequest $request): JsonResponse
    {
        try {
            $this->messageService->delete($request->validated());
            return response()->json([
                'status' => true,
                'message' => 'تم الحذف بنجاح',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'حدث خطأ أثناء الحذف',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function searchConversations(ConversationListRequest $request)
    {
        $conversations = $this->messageService->getUserConversations(
            $request->search(),
            $request->page(),
            $request->perPage()
        );

        return ConversationResource::collection($conversations)->additional(['status' => true]);
    }
}
