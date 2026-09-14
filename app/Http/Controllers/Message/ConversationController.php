<?php

declare(strict_types=1);

namespace App\Http\Controllers\Message;

use App\Http\Controllers\Controller;
use App\Http\Requests\Message\ConversationListRequest;
use App\Http\Requests\Message\MarkConversationReadRequest;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use App\Models\User;
use App\Repositories\Message\Queries\ConversationMessagesQuery;
use App\Repositories\Message\Queries\ConversationThreadsQuery;
use App\Repositories\Message\Queries\UnreadConversationCounter;
use App\Services\Message\Actions\MarkConversationReadAction;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;

#[Group(name: 'المحادثات', description: 'قائمة محادثات المستخدم ورسائلها وحالة القراءة.', weight: 8)]
class ConversationController extends Controller
{
    public function __construct(
        private readonly ConversationThreadsQuery $threads,
        private readonly UnreadConversationCounter $unread,
    ) {}

    #[Endpoint(title: 'عرض رسائل محادثة', description: 'يعرض رسائل المستخدم الحالي مع شريك محدد، ويضيف ملخصًا عامًا للشريك دون طلب ملفه في استجابة منفصلة.')]
    #[PathParameter('userId', description: 'المعرّف الرقمي لشريك المحادثة.')]
    #[QueryParameter('page', description: 'صفحة الرسائل المطلوبة، والقيمة الافتراضية 1.')]
    #[Response(200, description: 'رسائل المحادثة مقسمة إلى صفحات مع بيانات الشريك.')]
    #[Response(401, description: 'المستخدم غير مسجل الدخول.')]
    public function show(int $userId, ConversationMessagesQuery $messages): AnonymousResourceCollection
    {
        return MessageResource::collection(
            $messages->paginate((int) Auth::id(), $userId)
        )->additional(['status' => true, 'partner' => $this->partner($userId)]);
    }

    #[Endpoint(title: 'عرض قائمة المحادثات', description: 'يعرض محادثات المستخدم من الأحدث إلى الأقدم مع عدد الرسائل غير المقروءة لكل شريك والإجمالي.')]
    #[QueryParameter('page', description: 'رقم الصفحة.')]
    #[QueryParameter('per_page', description: 'عدد المحادثات في الصفحة، بحد أقصى 100.')]
    #[Response(200, description: 'المحادثات مقسمة إلى صفحات.')]
    public function index(ConversationListRequest $request): AnonymousResourceCollection
    {
        return ConversationResource::collection($this->paginate($request))->additional([
            'status' => true,
            'TotalUnreadConversationsCount' => $this->unread->forUser((int) Auth::id()),
        ]);
    }

    #[Endpoint(title: 'البحث في المحادثات', description: 'يبحث في أسماء شركاء محادثات المستخدم الحالي مع التقسيم إلى صفحات.')]
    #[QueryParameter('search', description: 'جزء من اسم شريك المحادثة.')]
    #[QueryParameter('page', description: 'رقم الصفحة.')]
    #[Response(200, description: 'المحادثات المطابقة مقسمة إلى صفحات.')]
    public function search(ConversationListRequest $request): AnonymousResourceCollection
    {
        return ConversationResource::collection($this->paginate($request))
            ->additional(['status' => true]);
    }

    #[Endpoint(title: 'تسجيل قراءة محادثة', description: 'يعتبر رسائل الشريك المحدد مقروءة لدى المستخدم الحالي.')]
    #[BodyParameter('user_id', description: 'المعرّف الرقمي لشريك المحادثة.', required: true, type: 'integer')]
    #[Response(200, description: 'تم تسجيل قراءة المحادثة.')]
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

    private function partner(int $userId): ?array
    {
        $user = User::withTrashed()
            ->select(['id', 'name', 'logo', 'country_id', 'state_id', 'city_id', 'created_at', 'deleted_at'])
            ->with(['country:id,name', 'state:id,name', 'city:id,name'])
            ->withCount('receivedSellerRatings')
            ->withAvg('receivedSellerRatings', 'rating')
            ->find($userId);

        if ($user === null) {
            return null;
        }

        return [
            'id' => (int) $user->id,
            'name' => $user->name,
            'logo' => $user->logo,
            'location' => implode(', ', array_filter([$user->country?->name, $user->state?->name, $user->city?->name])),
            'joined_at' => $user->created_at?->toIso8601String(),
            'rating_summary' => [
                'average' => round((float) ($user->received_seller_ratings_avg_rating ?? 0), 2),
                'count' => (int) ($user->received_seller_ratings_count ?? 0),
            ],
        ];
    }
}
