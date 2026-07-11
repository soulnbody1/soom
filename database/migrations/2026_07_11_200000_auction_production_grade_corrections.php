<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Production-grade corrective migration for auction system.
 * Adds configuration_version_id, missing indexes, and unique constraints.
 */
return new class extends Migration
{
    private function indexExists(string $table, string $indexName): bool
    {
        $conn = Schema::getConnection();
        $db = $conn->getDatabaseName();
        $prefix = $conn->getTablePrefix();
        $prefixedTable = $prefix . $table;

        $result = $conn->select(
            "SELECT 1 FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ? LIMIT 1",
            [$db, $prefixedTable, $indexName]
        );

        return ! empty($result);
    }

    public function up(): void
    {
        // 1. Add configuration_version_id to auctions table
        if (Schema::hasTable('auctions') && ! Schema::hasColumn('auctions', 'configuration_version_id')) {
            Schema::table('auctions', function (Blueprint $table) {
                $table->foreignId('configuration_version_id')
                    ->nullable()
                    ->after('terms_version_id')
                    ->constrained('auction_configuration_versions')
                    ->nullOnDelete();
            });
        }

        // 2. Add composite indexes for scheduler queries
        if (Schema::hasTable('auctions')) {
            Schema::table('auctions', function (Blueprint $table) {
                if (! $this->indexExists('auctions', 'idx_auctions_status_starts')) {
                    $table->index(['status', 'starts_at'], 'idx_auctions_status_starts');
                }
                if (! $this->indexExists('auctions', 'idx_auctions_status_ends')) {
                    $table->index(['status', 'ends_at'], 'idx_auctions_status_ends');
                }
            });
        }

        // 3. Add composite indexes for bid queries and concurrency
        if (Schema::hasTable('auction_bids')) {
            Schema::table('auction_bids', function (Blueprint $table) {
                if (! $this->indexExists('auction_bids', 'idx_bids_auction_amount')) {
                    $table->index(['auction_id', 'amount_minor', 'id'], 'idx_bids_auction_amount');
                }
                if (! $this->indexExists('auction_bids', 'idx_bids_auction_bidder')) {
                    $table->index(['auction_id', 'bidder_id'], 'idx_bids_auction_bidder');
                }
            });
        }

        // 4. Add indexes for deposits
        if (Schema::hasTable('auction_deposits')) {
            Schema::table('auction_deposits', function (Blueprint $table) {
                if (! $this->indexExists('auction_deposits', 'idx_deposits_auction_user_type')) {
                    $table->index(['auction_id', 'user_id', 'type', 'status'], 'idx_deposits_auction_user_type');
                }
            });
        }

        // 5. Add indexes for payment submissions
        if (Schema::hasTable('payment_submissions')) {
            Schema::table('payment_submissions', function (Blueprint $table) {
                if (! $this->indexExists('payment_submissions', 'idx_submissions_status_created')) {
                    $table->index(['status', 'created_at'], 'idx_submissions_status_created');
                }
            });
        }

        // 6. Add unique constraint on payment_transactions for provider uniqueness
        if (Schema::hasTable('payment_transactions')) {
            Schema::table('payment_transactions', function (Blueprint $table) {
                if (! $this->indexExists('payment_transactions', 'uniq_provider_txn')) {
                    $table->unique(['provider', 'provider_transaction_id'], 'uniq_provider_txn');
                }
            });
        }

        // 7. Add indexes for refund transactions
        if (Schema::hasTable('refund_transactions')) {
            Schema::table('refund_transactions', function (Blueprint $table) {
                if (! $this->indexExists('refund_transactions', 'idx_refunds_status_created')) {
                    $table->index(['status', 'created_at'], 'idx_refunds_status_created');
                }
            });
        }

        // 8. Add indexes for outbox messages
        if (Schema::hasTable('outbox_messages')) {
            Schema::table('outbox_messages', function (Blueprint $table) {
                if (! $this->indexExists('outbox_messages', 'idx_outbox_status_available')) {
                    $table->index(['status', 'available_at'], 'idx_outbox_status_available');
                }
            });
        }

        // 9. Add indexes for settlements
        if (Schema::hasTable('auction_settlements')) {
            Schema::table('auction_settlements', function (Blueprint $table) {
                if (! $this->indexExists('auction_settlements', 'idx_settlements_auction_status')) {
                    $table->index(['auction_id', 'status'], 'idx_settlements_auction_status');
                }
            });
        }

        // 10. Add indexes for participants
        if (Schema::hasTable('auction_participants')) {
            Schema::table('auction_participants', function (Blueprint $table) {
                if (! $this->indexExists('auction_participants', 'idx_participants_auction_user')) {
                    $table->index(['auction_id', 'user_id'], 'idx_participants_auction_user');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('auction_participants')) {
            Schema::table('auction_participants', function (Blueprint $table) {
                $table->dropIndex('idx_participants_auction_user');
            });
        }

        if (Schema::hasTable('auction_settlements')) {
            Schema::table('auction_settlements', function (Blueprint $table) {
                $table->dropIndex('idx_settlements_auction_status');
            });
        }

        if (Schema::hasTable('outbox_messages')) {
            Schema::table('outbox_messages', function (Blueprint $table) {
                $table->dropIndex('idx_outbox_status_available');
            });
        }

        if (Schema::hasTable('refund_transactions')) {
            Schema::table('refund_transactions', function (Blueprint $table) {
                $table->dropIndex('idx_refunds_status_created');
            });
        }

        if (Schema::hasTable('payment_transactions')) {
            Schema::table('payment_transactions', function (Blueprint $table) {
                $table->dropUnique('uniq_provider_txn');
            });
        }

        if (Schema::hasTable('payment_submissions')) {
            Schema::table('payment_submissions', function (Blueprint $table) {
                $table->dropIndex('idx_submissions_status_created');
            });
        }

        if (Schema::hasTable('auction_deposits')) {
            Schema::table('auction_deposits', function (Blueprint $table) {
                $table->dropIndex('idx_deposits_auction_user_type');
            });
        }

        if (Schema::hasTable('auction_bids')) {
            Schema::table('auction_bids', function (Blueprint $table) {
                $table->dropIndex('idx_bids_auction_amount');
                $table->dropIndex('idx_bids_auction_bidder');
            });
        }

        if (Schema::hasTable('auctions')) {
            Schema::table('auctions', function (Blueprint $table) {
                $table->dropIndex('idx_auctions_status_starts');
                $table->dropIndex('idx_auctions_status_ends');
            });

            if (Schema::hasColumn('auctions', 'configuration_version_id')) {
                Schema::table('auctions', function (Blueprint $table) {
                    $table->dropForeign(['configuration_version_id']);
                    $table->dropColumn('configuration_version_id');
                });
            }
        }
    }
};
