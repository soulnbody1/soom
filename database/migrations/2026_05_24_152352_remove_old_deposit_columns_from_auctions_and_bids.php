<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('auction_deposits')) {
            $this->migrateExistingData();
        }

        $auctionColumns = array_values(array_filter([
            Schema::hasColumn('auctions', 'advertiser_deposit_paid') ? 'advertiser_deposit_paid' : null,
            Schema::hasColumn('auctions', 'advertiser_deposit_paid_at') ? 'advertiser_deposit_paid_at' : null,
            Schema::hasColumn('auctions', 'advertiser_deposit_transaction_id') ? 'advertiser_deposit_transaction_id' : null,
        ]));

        if ($auctionColumns !== []) {
            Schema::table('auctions', function (Blueprint $table) use ($auctionColumns) {
                $table->dropColumn($auctionColumns);
            });
        }

        $bidColumns = array_values(array_filter([
            Schema::hasColumn('auction_bids', 'deposit_paid') ? 'deposit_paid' : null,
            Schema::hasColumn('auction_bids', 'deposit_paid_at') ? 'deposit_paid_at' : null,
            Schema::hasColumn('auction_bids', 'deposit_transaction_id') ? 'deposit_transaction_id' : null,
            Schema::hasColumn('auction_bids', 'deposit_status') ? 'deposit_status' : null,
            Schema::hasColumn('auction_bids', 'deposit_processed_at') ? 'deposit_processed_at' : null,
        ]));

        if ($bidColumns !== []) {
            Schema::table('auction_bids', function (Blueprint $table) use ($bidColumns) {
                $table->dropColumn($bidColumns);
            });
        }
    }

    public function down(): void
    {
        Schema::table('auctions', function (Blueprint $table) {
            if (! Schema::hasColumn('auctions', 'advertiser_deposit_paid')) {
                $table->boolean('advertiser_deposit_paid')->default(false)->after('terms_accepted');
            }

            if (! Schema::hasColumn('auctions', 'advertiser_deposit_paid_at')) {
                $table->timestamp('advertiser_deposit_paid_at')->nullable()->after('advertiser_deposit_paid');
            }

            if (! Schema::hasColumn('auctions', 'advertiser_deposit_transaction_id')) {
                $table->string('advertiser_deposit_transaction_id')->nullable()->after('advertiser_deposit_paid_at');
            }
        });

        Schema::table('auction_bids', function (Blueprint $table) {
            if (! Schema::hasColumn('auction_bids', 'deposit_paid')) {
                $table->boolean('deposit_paid')->default(false)->after('winning_at');
            }

            if (! Schema::hasColumn('auction_bids', 'deposit_paid_at')) {
                $table->timestamp('deposit_paid_at')->nullable()->after('deposit_paid');
            }

            if (! Schema::hasColumn('auction_bids', 'deposit_transaction_id')) {
                $table->string('deposit_transaction_id')->nullable()->after('deposit_paid_at');
            }

            if (! Schema::hasColumn('auction_bids', 'deposit_status')) {
                $table->enum('deposit_status', ['held', 'refunded', 'forfeited', 'applied_to_payment'])
                    ->default('held')
                    ->after('deposit_transaction_id');
            }

            if (! Schema::hasColumn('auction_bids', 'deposit_processed_at')) {
                $table->timestamp('deposit_processed_at')->nullable()->after('deposit_status');
            }
        });
    }

    private function migrateExistingData(): void
    {
        if (Schema::hasColumn('auctions', 'advertiser_deposit_paid')) {
            $auctions = DB::table('auctions')
                ->where('advertiser_deposit_paid', true)
                ->get([
                    'id',
                    'user_id',
                    'advertiser_deposit_paid_at',
                    'advertiser_deposit_transaction_id',
                    'deposit_amount',
                ]);

            foreach ($auctions as $auction) {
                DB::table('auction_deposits')->updateOrInsert(
                    [
                        'depositable_id' => $auction->id,
                        'depositable_type' => 'App\\Models\\Auction',
                        'deposit_type' => 'advertiser',
                    ],
                    [
                        'user_id' => $auction->user_id,
                        'paid' => true,
                        'paid_at' => $auction->advertiser_deposit_paid_at ?? now(),
                        'transaction_id' => $auction->advertiser_deposit_transaction_id ?? ('MIGRATED_' . $auction->id),
                        'amount' => $auction->deposit_amount ?? 0,
                        'deposit_status' => 'held',
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        }

        if (Schema::hasColumn('auction_bids', 'deposit_paid')) {
            $bids = DB::table('auction_bids')
                ->join('auctions', 'auction_bids.auction_id', '=', 'auctions.id')
                ->where('auction_bids.deposit_paid', true)
                ->get([
                    'auction_bids.id',
                    'auction_bids.user_id',
                    'auction_bids.deposit_paid_at',
                    'auction_bids.deposit_transaction_id',
                    'auction_bids.deposit_status',
                    'auction_bids.deposit_processed_at',
                    'auctions.deposit_amount',
                ]);

            foreach ($bids as $bid) {
                DB::table('auction_deposits')->updateOrInsert(
                    [
                        'depositable_id' => $bid->id,
                        'depositable_type' => 'App\\Models\\AuctionBid',
                        'deposit_type' => 'bidder',
                    ],
                    [
                        'user_id' => $bid->user_id,
                        'paid' => true,
                        'paid_at' => $bid->deposit_paid_at ?? now(),
                        'transaction_id' => $bid->deposit_transaction_id ?? ('MIGRATED_BID_' . $bid->id),
                        'amount' => $bid->deposit_amount ?? 0,
                        'deposit_status' => $bid->deposit_status ?? 'held',
                        'processed_at' => $bid->deposit_processed_at,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        }
    }
};
