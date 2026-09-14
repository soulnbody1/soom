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
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

#[Group(name: 'المحادثات', description: 'إرسال الرسائل وحذفها وإرفاق إعلان اختياري بمعرّفه العام ULID.', weight: 8)]
class MessageController extends Controller
{
    #[Endpoint(title: 'إرسال رسالة', description: 'يرسل رسالة إلى مستخدم آخر ويمكن ربطها بإعلان نشط باستخدام ULID العام دون كشف رقمه الداخلي.')]
    #[BodyParameter('receiver_id', description: 'المعرّف الرقمي لمستلم الرسالة.', required: true, type: 'integer')]
    #[BodyParameter('ad_id', description: 'المعرّف العام ULID للإعلان المرتبط اختياريًا.', type: 'string')]
    #[BodyParameter('content', description: 'نص الرسالة، ويصبح اختياريًا عند رفع ملف.', type: 'string')]
    #[BodyParameter('file', description: 'مرفق اختياري.', type: 'file')]
    #[Response(200, description: 'تم إرسال الرسالة وإرجاع بياناتها.')]
    #[Response(422, description: 'بيانات الرسالة أو ULID الإعلان غير صحيحة.')]
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

    #[Endpoint(title: 'حذف رسائل', description: 'يحذف مجموعة رسائل من محادثة المستخدم وفق الصلاحيات المتاحة.')]
    #[BodyParameter('user_id', description: 'المعرّف الرقمي لشريك المحادثة.', required: true, type: 'integer')]
    #[BodyParameter('message_ids', description: 'معرّفات الرسائل المطلوب حذفها.', required: true, type: 'array')]
    #[Response(200, description: 'تم حذف الرسائل.')]
    #[Response(403, description: 'المستخدم غير مخول بحذف رسالة من المجموعة.')]
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
