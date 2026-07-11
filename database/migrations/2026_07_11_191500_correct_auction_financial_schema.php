<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payment_submissions')) {
            Schema::table('payment_submissions', function (Blueprint $table) {
                if (! Schema::hasColumn('payment_submissions', 'receipt_disk')) {
                    $table->string('receipt_disk')->default('spaces_private');
                }
            });

            DB::table('payment_submissions')
                ->where('receipt_disk', 'spaces')
                ->update(['receipt_disk' => 'spaces_private']);

            $this->replacePaymentSubmissionUniqueKey();
        }

        if (Schema::hasTable('auction_settlements')) {
            Schema::table('auction_settlements', function (Blueprint $table) {
                if (! Schema::hasColumn('auction_settlements', 'seller_handover_confirmed_at')) {
                    $table->timestampTz('seller_handover_confirmed_at')->nullable();
                }

                if (! Schema::hasColumn('auction_settlements', 'buyer_receipt_confirmed_at')) {
                    $table->timestampTz('buyer_receipt_confirmed_at')->nullable();
                }
            });
        }

        if (Schema::hasTable('outbox_messages')) {
            Schema::table('outbox_messages', function (Blueprint $table) {
                if (! Schema::hasColumn('outbox_messages', 'event_id')) {
                    $table->char('event_id', 26)->nullable();
                }

                if (! Schema::hasColumn('outbox_messages', 'locked_at')) {
                    $table->timestampTz('locked_at')->nullable();
                }

                if (! Schema::hasColumn('outbox_messages', 'locked_by')) {
                    $table->string('locked_by')->nullable();
                }

                if (! Schema::hasColumn('outbox_messages', 'processed_at')) {
                    $table->timestampTz('processed_at')->nullable();
                }

                if (! Schema::hasColumn('outbox_messages', 'failed_at')) {
                    $table->timestampTz('failed_at')->nullable();
                }
            });

            DB::table('outbox_messages')
                ->whereNull('event_id')
                ->orderBy('id')
                ->update(['event_id' => DB::raw('public_id')]);

            try {
                Schema::table('outbox_messages', function (Blueprint $table) {
                    $table->unique('event_id', 'uq_outbox_event_id');
                });
            } catch (Throwable) {
                // The key may already exist on databases repaired manually.
            }
        }

        $this->createMissingGovernanceTables();
    }

    public function down(): void
    {
        Schema::dropIfExists('auction_winner_reassignments');
        Schema::dropIfExists('auction_disputes');
        Schema::dropIfExists('auction_configuration_versions');
    }

    private function replacePaymentSubmissionUniqueKey(): void
    {
        try {
            Schema::table('payment_submissions', function (Blueprint $table) {
                $table->dropUnique('uq_payment_submission_idempotency');
            });
        } catch (Throwable) {
            // Older or partially repaired databases may not have this key name.
        }

        try {
            Schema::table('payment_submissions', function (Blueprint $table) {
                $table->unique(['auction_id', 'user_id', 'purpose', 'idempotency_key'], 'uq_payment_submission_idempotency');
            });
        } catch (Throwable) {
            // Keep migration idempotent across MySQL/PostgreSQL/SQLite dev databases.
        }
    }

    private function createMissingGovernanceTables(): void
    {
        if (! Schema::hasTable('auction_configuration_versions')) {
            Schema::create('auction_configuration_versions', function (Blueprint $table) {
                $table->id();
                $table->char('public_id', 26)->unique();
                $table->unsignedInteger('version_number')->unique();
                $table->json('configuration');
                $table->boolean('is_active')->default(false);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestampTz('published_at')->nullable();
                $table->timestampsTz();
            });
        }

        if (! Schema::hasTable('auction_disputes')) {
            Schema::create('auction_disputes', function (Blueprint $table) {
                $table->id();
                $table->char('public_id', 26)->unique();
                $table->foreignId('auction_id')->constrained('auctions')->restrictOnDelete();
                $table->foreignId('settlement_id')->nullable()->constrained('auction_settlements')->restrictOnDelete();
                $table->foreignId('opened_by')->constrained('users')->restrictOnDelete();
                $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('status', 40)->default('open');
                $table->string('reason');
                $table->text('resolution_note')->nullable();
                $table->timestampTz('opened_at');
                $table->timestampTz('resolved_at')->nullable();
                $table->timestampsTz();

                $table->index(['auction_id', 'status'], 'idx_auction_disputes_auction_status');
            });
        }

        if (! Schema::hasTable('auction_winner_reassignments')) {
            Schema::create('auction_winner_reassignments', function (Blueprint $table) {
                $table->id();
                $table->char('public_id', 26)->unique();
                $table->foreignId('auction_id')->constrained('auctions')->restrictOnDelete();
                $table->foreignId('from_bid_id')->nullable()->constrained('auction_bids')->restrictOnDelete();
                $table->foreignId('to_bid_id')->nullable()->constrained('auction_bids')->restrictOnDelete();
                $table->foreignId('from_user_id')->nullable()->constrained('users')->restrictOnDelete();
                $table->foreignId('to_user_id')->nullable()->constrained('users')->restrictOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('reason');
                $table->json('metadata')->nullable();
                $table->timestampTz('created_at')->useCurrent();

                $table->index(['auction_id', 'created_at'], 'idx_winner_reassignments_auction');
            });
        }
    }
};
