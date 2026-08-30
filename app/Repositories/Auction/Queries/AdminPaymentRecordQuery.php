<?php

declare(strict_types=1);

namespace App\Repositories\Auction\Queries;

use App\Models\Auction\PaymentSubmission;
use App\Models\Auction\PaymentTransaction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class AdminPaymentRecordQuery
{
    private const TRANSACTION_RELATIONS = [
        'auction:id,public_id,title',
        'user:id,name',
        'paymentMethod',
        'submission.paymentMethod',
        'refunds',
    ];

    private const SUBMISSION_RELATIONS = [
        'auction:id,public_id,title',
        'user:id,name',
        'paymentMethod',
        'deposit',
    ];

    public function paginate(array $filters, int $perPage, int $page): LengthAwarePaginator
    {
        $base = $this->filtered($filters);
        $total = (clone $base)->count();

        $rows = $base
            ->orderByDesc('created_at')
            ->orderByDesc('source_id')
            ->forPage($page, $perPage)
            ->get();

        return new Paginator(
            $this->hydrate($rows),
            $total,
            $perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath()]
        );
    }

    public function statusCounts(array $filters): array
    {
        unset($filters['status']);

        return $this->filtered($filters)
            ->select('record_status', DB::raw('count(*) as aggregate'))
            ->groupBy('record_status')
            ->pluck('aggregate', 'record_status')
            ->map(fn ($count): int => (int) $count)
            ->all();
    }

    public function findByPublicId(string $publicId): PaymentTransaction|PaymentSubmission|null
    {
        $transaction = PaymentTransaction::with(self::TRANSACTION_RELATIONS)->where('public_id', $publicId)->first();

        if ($transaction) {
            return $transaction;
        }

        return PaymentSubmission::with(self::SUBMISSION_RELATIONS)->where('public_id', $publicId)->first();
    }

    private function filtered(array $filters): Builder
    {
        $query = DB::query()->fromSub($this->union(), 'payment_records');

        if (($status = $filters['status'] ?? null) !== null) {
            $query->where('record_status', $status);
        }

        if (($purpose = $filters['purpose'] ?? null) !== null) {
            $query->where('purpose', $purpose);
        }

        if (($channel = $filters['channel'] ?? null) !== null) {
            $query->where('channel', $channel);
        }

        if (($provider = $filters['provider'] ?? null) !== null) {
            $query->where('provider', $provider);
        }

        if (($userId = $filters['user_id'] ?? null) !== null) {
            $query->where('user_id', (int) $userId);
        }

        if (($auctionId = $filters['auction_id'] ?? null) !== null) {
            $query->where('auction_id', $this->resolveAuctionId((string) $auctionId));
        }

        return $query;
    }

    private function union(): Builder
    {
        $transactions = DB::table('payment_transactions')
            ->select([
                DB::raw("'transaction' as source"),
                'id as source_id',
                'auction_id',
                'user_id',
                'purpose',
                'status as record_status',
                'provider',
                DB::raw("case when provider = 'manual' then 'manual' else 'online' end as channel"),
                'created_at',
            ]);

        $submissions = DB::table('payment_submissions')
            ->select([
                DB::raw("'submission' as source"),
                'id as source_id',
                'auction_id',
                'user_id',
                'purpose',
                DB::raw("case when status = 'approved' then 'succeeded' else status end as record_status"),
                DB::raw("'manual' as provider"),
                DB::raw("'manual' as channel"),
                'created_at',
            ])
            ->whereNotExists(function (Builder $query): void {
                $query->select(DB::raw(1))
                    ->from('payment_transactions')
                    ->whereColumn('payment_transactions.payment_submission_id', 'payment_submissions.id');
            });

        return $transactions->unionAll($submissions);
    }

    private function resolveAuctionId(string $value): int
    {
        if (ctype_digit($value)) {
            return (int) $value;
        }

        return (int) DB::table('auctions')->where('public_id', $value)->value('id');
    }

    private function hydrate(Collection $rows): Collection
    {
        $transactionIds = $rows->where('source', 'transaction')->pluck('source_id')->all();
        $submissionIds = $rows->where('source', 'submission')->pluck('source_id')->all();

        $transactions = $transactionIds === []
            ? collect()
            : PaymentTransaction::with(self::TRANSACTION_RELATIONS)->whereKey($transactionIds)->get()->keyBy('id');

        $submissions = $submissionIds === []
            ? collect()
            : PaymentSubmission::with(self::SUBMISSION_RELATIONS)->whereKey($submissionIds)->get()->keyBy('id');

        return $rows
            ->map(fn (object $row) => $row->source === 'transaction'
                ? $transactions->get($row->source_id)
                : $submissions->get($row->source_id))
            ->filter()
            ->values();
    }
}
