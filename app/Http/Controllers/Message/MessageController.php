<?php

declare(strict_types=1);

namespace App\Http\Controllers\Message;

use App\Http\Controllers\Controller;
use App\Http\Requests\DeleteMessageRequest;
use App\Http\Requests\StoreMessageRequest;
use App\Http\Resources\MessageResource;
use App\Models\User;
use App\Services\Message\Actions\DeleteMessagesAction;
use App\Services\Message\Actions\SendMessageAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class MessageController extends Controller
{
    public function store(StoreMessageRequest $request, SendMessageAction $sendMessage): JsonResponse
    {
        $sender = $request->user();
        $receiver = User::findOrFail($request->receiverId());

        try {
            $message = $sendMessage->execute($sender, $receiver, $request->payload());
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::error('فشل في إرسال الرسالة: '.$exception->getMessage(), [
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]);

            return response()->json([
                'status' => false,
                'message' => 'حدث خطأ أثناء إرسال الرسالة. حاول مرة أخرى لاحقًا.',
            ], 500);
        }

        return response()->json([
            'status' => true,
            'message' => 'تم إرسال الرسالة بنجاح',
            'data' => new MessageResource($message),
        ]);
    }

    public function delete(DeleteMessageRequest $request, DeleteMessagesAction $deleteMessages): JsonResponse
    {
        try {
            $deleteMessages->execute(
                $request->user(),
                (int) $request->input('user_id'),
                $request->messageIds()
            );
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::error('فشل في حذف الرسائل: '.$exception->getMessage(), [
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]);

            return response()->json([
                'status' => false,
                'message' => 'حدث خطأ أثناء الحذف',
            ], 500);
        }

        return response()->json([
            'status' => true,
            'message' => 'تم الحذف بنجاح',
        ]);
    }
}
