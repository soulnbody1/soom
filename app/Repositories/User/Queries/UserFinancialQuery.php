<?php

declare(strict_types=1);

namespace App\Repositories\User\Queries;

use App\Models\Auction\AuctionDeposit;
use App\Models\Auction\AuctionSellerPayout;
use App\Models\Auction\PaymentSubmission;
use App\Models\Auction\PaymentTransaction;
use App\Models\Auction\PayoutDestination;
use App\Models\Auction\RefundTransaction;
use App\Models\Market;
use App\Models\User;
use App\Services\Market\MarketQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final class UserFinancialQuery
{
    private const AUCTION_RELATION = 'auction:id,public_id,title,currency_code,status';

    /** @var array<int, string>|null */
    private ?array $codes = null;

    public function __construct(private readonly MarketQuery $markets) {}

    public function summary(User $user): array
    {
        return [
            'refunds' => $this->groupTotals('refund_transactions', 'user_id', $user->id),
            'seller_payouts' => $this->groupTotals('auction_seller_payouts', 'seller_id', $user->id),
            'deposits' => $this->groupTotals('auction_deposits', 'user_id', $user->id, 'held_amount_minor'),
            'payment_submissions' => $this->groupTotals('payment_submissions', 'user_id', $user->id),
            'payment_transactions' => $this->groupTotals('payment_transactions', 'user_id', $user->id),
        ];
    }

    public function paginateRefunds(User $user, array $filters, int $perPage): LengthAwarePaginator
    {
        return RefundTransaction::query()
            ->select(['id', 'public_id', 'auction_id', 'destination_id', 'recipient_name', 'identifier_type', 'identifier_value', 'status', 'amount_minor', 'currency_code', 'reason', 'obligation_type', 'proof_path', 'processed_at', 'succeeded_at', 'failed_at', 'cancelled_at', 'created_at'])
            ->where('user_id', $user->id)
            ->with(self::AUCTION_RELATION)
            ->when($filters['status'] ?? null, fn (Builder $query, $value) => $query->where('status', $value))
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function paginatePayouts(User $user, array $filters, int $perPage): LengthAwarePaginator
    {
        return AuctionSellerPayout::query()
            ->select(['id', 'public_id', 'auction_id', 'settlement_id', 'status', 'amount_minor', 'winning_amount_minor', 'platform_fee_minor', 'currency_code', 'recipient_name', 'identifier_type', 'identifier_value', 'transfer_reference', 'proof_path', 'hold_reason', 'failure_reason', 'paid_at', 'failed_at', 'held_at', 'created_at'])
            ->where('seller_id', $user->id)
            ->with(self::AUCTION_RELATION)
            ->when($filters['status'] ?? null, fn (Builder $query, $value) => $query->where('status', $value))
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function paginateDeposits(User $user, array $filters, int $perPage): LengthAwarePaginator
    {
        return AuctionDeposit::query()
            ->select(['id', 'public_id', 'auction_id', 'type', 'status', 'required_amount_minor', 'held_amount_minor', 'applied_amount_minor', 'refunded_amount_minor', 'forfeited_amount_minor', 'currency_code', 'hold_reason', 'submitted_at', 'held_at', 'released_at', 'created_at'])
            ->where('user_id', $user->id)
            ->with(self::AUCTION_RELATION)
            ->when($filters['status'] ?? null, fn (Builder $query, $value) => $query->where('status', $value))
            ->when($filters['type'] ?? null, fn (Builder $query, $value) => $query->where('type', $value))
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function paginateSubmissions(User $user, array $filters, int $perPage): LengthAwarePaginator
    {
        return PaymentSubmission::query()
            ->select(['id', 'public_id', 'auction_id', 'payment_method_id', 'purpose', 'status', 'amount_minor', 'currency_code', 'provider_reference', 'receipt_path', 'review_note', 'submitted_at', 'reviewed_at', 'created_at'])
            ->where('user_id', $user->id)
            ->with([self::AUCTION_RELATION, 'paymentMethod:id,name'])
            ->when($filters['status'] ?? null, fn (Builder $query, $value) => $query->where('status', $value))
            ->when($filters['purpose'] ?? null, fn (Builder $query, $value) => $query->where('purpose', $value))
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function paginateTransactions(User $user, array $filters, int $perPage): LengthAwarePaginator
    {
        return PaymentTransaction::query()
            ->select(['id', 'public_id', 'auction_id', 'payment_submission_id', 'purpose', 'status', 'amount_minor', 'currency_code', 'provider', 'processed_at', 'created_at'])
            ->where('user_id', $user->id)
            ->with(self::AUCTION_RELATION)
            ->when($filters['status'] ?? null, fn (Builder $query, $value) => $query->where('status', $value))
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function destinations(User $user)
    {
        return PayoutDestination::query()
            ->select(['id', 'public_id', 'recipient_name', 'identifier_type', 'identifier_value', 'is_default', 'created_at', 'updated_at'])
            ->where('user_id', $user->id)
            ->orderByDesc('is_default')
            ->orderByDesc('id')
            ->get();
    }

    private function groupTotals(string $table, string $column, int $userId, string $amountColumn = 'amount_minor'): array
    {
        $codes = $this->marketCodes();

        return $this->markets->table($table)
            ->selectRaw('market_id, status, currency_code, COUNT(*) as count, SUM('.$amountColumn.') as total_minor')
            ->where($column, $userId)
            ->groupBy('market_id', 'status', 'currency_code')
            ->orderBy('market_id')
            ->orderBy('status')
            ->get()
            ->map(fn (object $row): array => [
                'market' => $codes[(int) $row->market_id] ?? null,
                'status' => $row->status,
                'currency' => $row->currency_code,
                'count' => (int) $row->count,
                'total_minor' => (int) $row->total_minor,
            ])
            ->values()
            ->all();
    }

    /** @return array<int, string> */
    private function marketCodes(): array
    {
        return $this->codes ??= Market::query()->pluck('code', 'id')
            ->map(fn (string $code): string => strtolower($code))
            ->all();
    }
}
