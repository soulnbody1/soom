<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('auction_configuration_snapshots')) {
            Schema::create('auction_configuration_snapshots', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('auction_id');
                $table->foreignId('source_configuration_version_id');
                $table->foreignId('terms_version_id')->nullable();
                $table->string('terms_hash', 64)->nullable();
                $table->char('currency_code', 3);
                $table->unsignedTinyInteger('currency_minor_unit');
                $table->unsignedBigInteger('minimum_bid_increment_minor');
                $table->string('reserve_price_policy', 80)->default('auction_row_reserve_amount');
                $table->boolean('auto_extend_enabled')->default(true);
                $table->unsignedInteger('auto_extend_window_seconds');
                $table->unsignedInteger('auto_extend_duration_seconds');
                $table->unsignedInteger('maximum_extensions');
                $table->unsignedBigInteger('seller_deposit_required_minor');
                $table->unsignedInteger('seller_deposit_payment_deadline_minutes')->nullable();
                $table->json('seller_deposit_policy');
                $table->unsignedBigInteger('bidder_deposit_required_minor');
                $table->string('bidder_deposit_payment_deadline_policy', 80)->default('auction_end');
                $table->string('non_winner_deposit_hold_policy', 120);
                $table->string('alternative_candidate_hold_policy', 120);
                $table->unsignedInteger('alternative_candidate_limit');
                $table->unsignedInteger('winner_payment_deadline_minutes');
                $table->unsignedInteger('handover_deadline_minutes');
                $table->string('platform_fee_type', 20);
                $table->unsignedInteger('platform_fee_value');
                $table->unsignedBigInteger('platform_fee_min_minor')->default(0);
                $table->unsignedBigInteger('platform_fee_max_minor')->nullable();
                $table->string('deposit_application_policy', 80)->default('apply_bidder_deposit_to_winning_amount');
                $table->json('winner_default_deposit_policy');
                $table->string('winner_default_forfeit_type', 40)->default('configured_policy');
                $table->unsignedBigInteger('winner_default_forfeit_value')->default(0);
                $table->boolean('alternative_winner_enabled')->default(true);
                $table->string('alternative_winner_selection_policy', 80)->default('next_highest_eligible_bid');
                $table->unsignedInteger('maximum_reassignments')->default(1);
                $table->json('cancellation_policies');
                $table->string('refund_processing_mode', 40)->default('manual');
                $table->string('required_acceptance_scope', 80)->default('auction_terms_version');
                $table->unsignedInteger('payment_submission_review_deadline_minutes')->nullable();
                $table->boolean('manual_override_allowed')->default(true);
                $table->boolean('manual_review_required_for_fraud')->default(true);
                $table->string('snapshot_hash', 64);
                $table->foreignId('created_by')->nullable();
                $table->timestampTz('finalized_at');
                $table->timestampsTz();

                $table->unique('auction_id', 'uq_auction_configuration_snapshot_auction');
                $table->unique('snapshot_hash', 'uq_auction_configuration_snapshot_hash');
                $table->index('source_configuration_version_id', 'idx_snapshot_source_version');
                $table->foreign('auction_id', 'fk_snapshot_auction')->references('id')->on('auctions')->restrictOnDelete();
                $table->foreign('source_configuration_version_id', 'fk_snapshot_source_cfg')->references('id')->on('auction_configuration_versions')->restrictOnDelete();
                $table->foreign('terms_version_id', 'fk_snapshot_terms')->references('id')->on('auction_terms_versions')->restrictOnDelete();
                $table->foreign('created_by', 'fk_snapshot_creator')->references('id')->on('users')->nullOnDelete();
            });
        }

        $this->addCheckConstraints();
    }

    public function down(): void
    {
        Schema::dropIfExists('auction_configuration_snapshots');
    }

    private function addCheckConstraints(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'pgsql'], true)) {
            return;
        }

        $statements = [
            'ALTER TABLE auction_configuration_snapshots ADD CONSTRAINT chk_snapshot_platform_fee CHECK (platform_fee_type IN (\'percentage\', \'fixed\'))',
            'ALTER TABLE auction_configuration_snapshots ADD CONSTRAINT chk_snapshot_deadlines CHECK (winner_payment_deadline_minutes > 0 AND handover_deadline_minutes > 0)',
            'ALTER TABLE auction_configuration_snapshots ADD CONSTRAINT chk_snapshot_candidate_limit CHECK (alternative_candidate_limit > 0)',
        ];

        foreach ($statements as $statement) {
            DB::statement($statement);
        }
    }
};
