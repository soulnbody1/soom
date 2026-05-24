<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // نقل البيانات القديمة إلى الجدول الجديد قبل حذف الأعمدة
        $this->migrateExistingData();

        // حذف الأعمدة القديمة من جدول auctions
        Schema::table('auctions', function (Blueprint $table) {
            $table->dropColumn([
                'advertiser_deposit_paid',
                'advertiser_deposit_paid_at',
                'advertiser_deposit_transaction_id',
            ]);
        });

        // حذف الأعمدة القديمة من جدول auction_bids
        Schema::table('auction_bids', function (Blueprint $table) {
            $table->dropColumn([
                'deposit_paid',
                'deposit_paid_at',
                'deposit_transaction_id',
                'deposit_status',
                'deposit_processed_at',
            ]);
        });
    }

    public function down(): void
    {
        // إعادة الأعمدة المحذوفة
        Schema::table('auctions', function (Blueprint $table) {
            $table->boolean('advertiser_deposit_paid')->default(false)->after('terms_accepted');
            $table->timestamp('advertiser_deposit_paid_at')->nullable()->after('advertiser_deposit_paid');
            $table->string('advertiser_deposit_transaction_id')->nullable()->after('advertiser_deposit_paid_at');
        });

        Schema::table('auction_bids', function (Blueprint $table) {
            $table->boolean('deposit_paid')->default(false)->after('winning_at');
            $table->timestamp('deposit_paid_at')->nullable()->after('deposit_paid');
            $table->string('deposit_transaction_id')->nullable()->after('deposit_paid_at');
            $table->enum('deposit_status', ['held', 'refunded', 'forfeited', 'applied_to_payment'])
                  ->default('held')->after('deposit_transaction_id');
            $table->timestamp('deposit_processed_at')->nullable()->after('deposit_status');
        });
    }

    /**
     * نقل البيانات الموجودة من الأعمدة القديمة إلى جدول auction_deposits
     */
    private function migrateExistingData(): void
    {
        // 1. تأمينات المعلنين (من جدول auctions)
        $auctions = DB::table('auctions')
            ->where('advertiser_deposit_paid', true)
            ->get(['id', 'user_id', 'advertiser_deposit_paid', 'advertiser_deposit_paid_at', 'advertiser_deposit_transaction_id']);

        foreach ($auctions as $auction) {
            DB::table('auction_deposits')->insert([
                'user_id' => $auction->user_id,
                'depositable_id' => $auction->id,
                'depositable_type' => 'App\Models\Auction',
                'paid' => true,
                'paid_at' => $auction->advertiser_deposit_paid_at ?? now(),
                'transaction_id' => $auction->advertiser_deposit_transaction_id ?? ('MIGRATED_' . $auction->id),
                'amount' => DB::table('auctions')->where('id', $auction->id)->value('deposit_amount') ?? 0,
                'deposit_status' => 'held',
                'deposit_type' => 'advertiser',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 2. تأمينات المزايدين (من جدول auction_bids)
        $bids = DB::table('auction_bids')
            ->where('deposit_paid', true)
            ->get(['id', 'user_id', 'auction_id', 'deposit_paid', 'deposit_paid_at', 'deposit_transaction_id', 'deposit_status', 'deposit_processed_at']);

        foreach ($bids as $bid) {
            DB::table('auction_deposits')->insert([
                'user_id' => $bid->user_id,
                'depositable_id' => $bid->id,
                'depositable_type' => 'App\Models\AuctionBid',
                'paid' => true,
                'paid_at' => $bid->deposit_paid_at ?? now(),
                'transaction_id' => $bid->deposit_transaction_id ?? ('MIGRATED_BID_' . $bid->id),
                'amount' => DB::table('auctions')->where('id', $bid->auction_id)->value('deposit_amount') ?? 0,
                'deposit_status' => $bid->deposit_status ?? 'held',
                'processed_at' => $bid->deposit_processed_at,
                'deposit_type' => 'bidder',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
};