<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('name');
            $table->string('code')->unique();
            $table->text('instructions')->nullable();
            $table->boolean('requires_manual_review')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('auction_terms_versions', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->unsignedInteger('version_number')->unique();
            $table->string('title');
            $table->longText('body');
            $table->boolean('is_active')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        Schema::create('auctions', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('seller_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('category_id')->constrained('categories')->restrictOnDelete();
            $table->foreignId('country_id')->constrained('countries')->restrictOnDelete();
            $table->foreignId('state_id')->nullable()->constrained('states')->nullOnDelete();
            $table->foreignId('city_id')->nullable()->constrained('cities')->nullOnDelete();
            $table->foreignId('terms_version_id')->nullable()->constrained('auction_terms_versions')->nullOnDelete();
            $table->char('currency_code', 3);
            $table->string('title');
            $table->text('description');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('status', 40)->default('draft');
            $table->unsignedBigInteger('starting_amount_minor');
            $table->unsignedBigInteger('reserve_amount_minor')->nullable();
            $table->unsignedBigInteger('minimum_bid_increment_minor');
            $table->unsignedBigInteger('seller_deposit_amount_minor')->default(0);
            $table->unsignedBigInteger('bidder_deposit_amount_minor')->default(0);
            $table->string('platform_fee_type', 20)->default('percentage');
            $table->unsignedInteger('platform_fee_basis_points')->default(0);
            $table->unsignedBigInteger('platform_fee_fixed_minor')->default(0);
            $table->unsignedInteger('winner_payment_deadline_hours')->default(48);
            $table->unsignedInteger('handover_deadline_hours')->default(72);
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('original_ends_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->unsignedInteger('extension_window_seconds')->default(300);
            $table->unsignedInteger('extension_duration_seconds')->default(600);
            $table->unsignedInteger('maximum_extension_count')->default(6);
            $table->unsignedInteger('extension_count')->default(0);
            $table->timestampTz('last_extended_at')->nullable();
            $table->unsignedBigInteger('current_leading_bid_id')->nullable();
            $table->unsignedBigInteger('winning_bid_id')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('ended_at')->nullable();
            $table->timestampTz('finalized_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['status', 'starts_at'], 'idx_auctions_status_starts');
            $table->index(['status', 'ends_at'], 'idx_auctions_status_ends');
            $table->index(['category_id', 'status', 'ends_at'], 'idx_auctions_category_status_ends');
            $table->index(['seller_id', 'status'], 'idx_auctions_seller_status');
            $table->index(['country_id', 'state_id', 'city_id'], 'idx_auctions_location');
        });

        Schema::create('auction_media', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('auction_id')->constrained('auctions')->cascadeOnDelete();
            $table->string('disk')->default('spaces');
            $table->string('path');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_primary')->default(false);
            $table->timestampsTz();

            $table->unique(['auction_id', 'sort_order'], 'uq_auction_media_order');
            $table->index(['auction_id', 'is_primary'], 'idx_auction_media_primary');
        });

        Schema::create('auction_participants', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('auction_id')->constrained('auctions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 40)->default('registered');
            $table->timestampTz('registered_at');
            $table->timestampTz('qualified_at')->nullable();
            $table->timestampTz('blocked_at')->nullable();
            $table->string('block_reason')->nullable();
            $table->timestampsTz();

            $table->unique(['auction_id', 'user_id'], 'uq_auction_participant_user');
            $table->index(['user_id', 'status'], 'idx_auction_participant_user_status');
        });

        Schema::create('auction_terms_acceptances', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('auction_id')->constrained('auctions')->cascadeOnDelete();
            $table->foreignId('participant_id')->constrained('auction_participants')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('terms_version_id')->constrained('auction_terms_versions')->restrictOnDelete();
            $table->string('ip_hash', 64)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestampTz('accepted_at');
            $table->timestampsTz();

            $table->unique(['auction_id', 'user_id', 'terms_version_id'], 'uq_auction_terms_acceptance');
        });

        Schema::create('auction_deposits', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('auction_id')->constrained('auctions')->restrictOnDelete();
            $table->foreignId('participant_id')->nullable()->constrained('auction_participants')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('type', 20);
            $table->string('status', 40)->default('pending_submission');
            $table->unsignedBigInteger('required_amount_minor');
            $table->unsignedBigInteger('held_amount_minor')->default(0);
            $table->unsignedBigInteger('applied_amount_minor')->default(0);
            $table->unsignedBigInteger('refunded_amount_minor')->default(0);
            $table->unsignedBigInteger('forfeited_amount_minor')->default(0);
            $table->char('currency_code', 3);
            $table->string('idempotency_key')->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('held_at')->nullable();
            $table->timestampTz('released_at')->nullable();
            $table->timestampsTz();

            $table->unique(['auction_id', 'user_id', 'type'], 'uq_auction_deposit_user_type');
            $table->unique(['auction_id', 'user_id', 'type', 'idempotency_key'], 'uq_auction_deposit_idempotency');
            $table->index(['auction_id', 'status'], 'idx_auction_deposits_status');
            $table->index(['user_id', 'status'], 'idx_auction_deposits_user_status');
        });

        Schema::create('payment_submissions', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('auction_id')->constrained('auctions')->restrictOnDelete();
            $table->foreignId('deposit_id')->nullable()->constrained('auction_deposits')->restrictOnDelete();
            $table->foreignId('settlement_id')->nullable();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('payment_method_id')->constrained('payment_methods')->restrictOnDelete();
            $table->string('purpose', 40);
            $table->string('status', 40)->default('pending_review');
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency_code', 3);
            $table->string('receipt_disk')->default('spaces');
            $table->string('receipt_path');
            $table->string('receipt_mime_type', 100);
            $table->unsignedBigInteger('receipt_size_bytes');
            $table->string('provider_reference')->nullable();
            $table->string('idempotency_key')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('review_note')->nullable();
            $table->timestampTz('submitted_at');
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['user_id', 'purpose', 'idempotency_key'], 'uq_payment_submission_idempotency');
            $table->index(['auction_id', 'purpose', 'status'], 'idx_payment_submissions_auction_status');
            $table->index(['user_id', 'status'], 'idx_payment_submissions_user_status');
        });

        Schema::create('auction_bids', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('auction_id')->constrained('auctions')->restrictOnDelete();
            $table->foreignId('participant_id')->constrained('auction_participants')->restrictOnDelete();
            $table->foreignId('bidder_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency_code', 3);
            $table->unsignedBigInteger('sequence_number');
            $table->foreignId('previous_bid_id')->nullable()->constrained('auction_bids')->nullOnDelete();
            $table->string('idempotency_key');
            $table->string('client_request_id')->nullable();
            $table->timestampTz('server_received_at');
            $table->timestampTz('accepted_at');
            $table->timestampsTz();

            $table->unique(['auction_id', 'sequence_number'], 'uq_auction_bid_sequence');
            $table->unique(['auction_id', 'bidder_id', 'idempotency_key'], 'uq_auction_bid_idempotency');
            $table->index(['auction_id', 'amount_minor', 'sequence_number'], 'idx_auction_bids_rank');
            $table->index(['bidder_id', 'accepted_at'], 'idx_auction_bids_bidder');
        });

        Schema::table('auctions', function (Blueprint $table) {
            $table->foreign('current_leading_bid_id', 'fk_auctions_current_bid')
                ->references('id')
                ->on('auction_bids')
                ->nullOnDelete();
            $table->foreign('winning_bid_id', 'fk_auctions_winning_bid')
                ->references('id')
                ->on('auction_bids')
                ->nullOnDelete();
        });

        Schema::create('auction_settlements', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('auction_id')->constrained('auctions')->restrictOnDelete();
            $table->foreignId('winning_bid_id')->constrained('auction_bids')->restrictOnDelete();
            $table->foreignId('winner_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 40)->default('payment_pending');
            $table->unsignedBigInteger('winning_amount_minor');
            $table->unsignedBigInteger('deposit_applied_minor')->default(0);
            $table->unsignedBigInteger('platform_fee_minor')->default(0);
            $table->unsignedBigInteger('seller_net_amount_minor')->default(0);
            $table->unsignedBigInteger('amount_due_minor');
            $table->unsignedBigInteger('amount_paid_minor')->default(0);
            $table->char('currency_code', 3);
            $table->timestampTz('payment_due_at');
            $table->timestampTz('handover_due_at')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('handover_completed_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->unique('auction_id', 'uq_auction_settlement_one');
            $table->unique('winning_bid_id', 'uq_auction_settlement_bid');
            $table->index(['winner_id', 'status'], 'idx_auction_settlement_winner_status');
        });

        Schema::table('payment_submissions', function (Blueprint $table) {
            $table->foreign('settlement_id', 'fk_payment_submissions_settlement')
                ->references('id')
                ->on('auction_settlements')
                ->restrictOnDelete();
        });

        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('payment_submission_id')->constrained('payment_submissions')->restrictOnDelete();
            $table->foreignId('auction_id')->constrained('auctions')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('purpose', 40);
            $table->string('status', 40);
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency_code', 3);
            $table->string('provider')->default('manual');
            $table->string('provider_transaction_id')->nullable();
            $table->string('provider_event_id')->nullable();
            $table->json('provider_payload')->nullable();
            $table->string('idempotency_key');
            $table->timestampTz('processed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['provider', 'provider_event_id'], 'uq_payment_transaction_provider_event');
            $table->unique(['purpose', 'idempotency_key'], 'uq_payment_transaction_idempotency');
            $table->index(['auction_id', 'purpose', 'status'], 'idx_payment_transactions_auction');
        });

        Schema::create('refund_transactions', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('auction_id')->constrained('auctions')->restrictOnDelete();
            $table->foreignId('deposit_id')->nullable()->constrained('auction_deposits')->restrictOnDelete();
            $table->foreignId('payment_transaction_id')->nullable()->constrained('payment_transactions')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 40)->default('pending');
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency_code', 3);
            $table->string('reason');
            $table->string('provider')->default('manual');
            $table->string('provider_refund_id')->nullable();
            $table->string('idempotency_key');
            $table->text('failure_reason')->nullable();
            $table->timestampTz('processed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['provider', 'idempotency_key'], 'uq_refund_idempotency');
            $table->index(['auction_id', 'status'], 'idx_refunds_auction_status');
            $table->index(['user_id', 'status'], 'idx_refunds_user_status');
        });

        Schema::create('auction_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('auction_id')->constrained('auctions')->cascadeOnDelete();
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40);
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_type', 20)->default('system');
            $table->string('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['auction_id', 'created_at'], 'idx_auction_status_history');
        });

        Schema::create('auction_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('auction_id')->nullable()->constrained('auctions')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type');
            $table->string('actor_type', 20)->default('system');
            $table->string('ip_hash', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['auction_id', 'event_type', 'created_at'], 'idx_auction_activity_event');
            $table->index(['user_id', 'created_at'], 'idx_auction_activity_user');
        });

        Schema::create('auction_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('auction_id')->unique()->constrained('auctions')->cascadeOnDelete();
            $table->unsignedBigInteger('views_count')->default(0);
            $table->unsignedBigInteger('unique_views_count')->default(0);
            $table->unsignedBigInteger('participants_count')->default(0);
            $table->unsignedBigInteger('bids_count')->default(0);
            $table->unsignedBigInteger('unique_bidders_count')->default(0);
            $table->unsignedBigInteger('extensions_count')->default(0);
            $table->timestampTz('last_bid_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('auction_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('auction_id')->constrained('auctions')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('viewer_hash', 64);
            $table->timestampTz('viewed_at');
            $table->timestampsTz();

            $table->unique(['auction_id', 'viewer_hash'], 'uq_auction_viewer_hash');
            $table->index(['auction_id', 'viewed_at'], 'idx_auction_views_time');
        });

        Schema::create('outbox_messages', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('topic');
            $table->string('event_type');
            $table->string('aggregate_type');
            $table->unsignedBigInteger('aggregate_id');
            $table->json('payload');
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('available_at');
            $table->timestampTz('published_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'available_at'], 'idx_outbox_status_available');
            $table->index(['aggregate_type', 'aggregate_id'], 'idx_outbox_aggregate');
        });

        $this->addCheckConstraints();
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_messages');
        Schema::dropIfExists('auction_views');
        Schema::dropIfExists('auction_metrics');
        Schema::dropIfExists('auction_activity_logs');
        Schema::dropIfExists('auction_status_history');
        Schema::dropIfExists('refund_transactions');
        Schema::dropIfExists('payment_transactions');
        Schema::table('payment_submissions', function (Blueprint $table) {
            $table->dropForeign('fk_payment_submissions_settlement');
        });
        Schema::dropIfExists('auction_settlements');
        Schema::table('auctions', function (Blueprint $table) {
            $table->dropForeign('fk_auctions_current_bid');
            $table->dropForeign('fk_auctions_winning_bid');
        });
        Schema::dropIfExists('auction_bids');
        Schema::dropIfExists('payment_submissions');
        Schema::dropIfExists('auction_deposits');
        Schema::dropIfExists('auction_terms_acceptances');
        Schema::dropIfExists('auction_participants');
        Schema::dropIfExists('auction_media');
        Schema::dropIfExists('auctions');
        Schema::dropIfExists('auction_terms_versions');
        Schema::dropIfExists('payment_methods');
    }

    private function addCheckConstraints(): void
    {
        $driver = DB::connection()->getDriverName();

        if (! in_array($driver, ['mysql', 'pgsql'], true)) {
            return;
        }

        $statements = [
            "ALTER TABLE auctions ADD CONSTRAINT chk_auction_amounts CHECK (reserve_amount_minor IS NULL OR reserve_amount_minor >= starting_amount_minor)",
            "ALTER TABLE auctions ADD CONSTRAINT chk_auction_time CHECK (ends_at IS NULL OR starts_at IS NULL OR ends_at > starts_at)",
            "ALTER TABLE auction_deposits ADD CONSTRAINT chk_deposit_amounts CHECK (held_amount_minor + applied_amount_minor + refunded_amount_minor + forfeited_amount_minor <= required_amount_minor)",
            "ALTER TABLE auction_settlements ADD CONSTRAINT chk_settlement_amounts CHECK (amount_due_minor + deposit_applied_minor = winning_amount_minor)",
        ];

        foreach ($statements as $statement) {
            DB::statement($statement);
        }
    }
};
