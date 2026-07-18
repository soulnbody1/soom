<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payout_destinations', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('recipient_name');
            $table->string('identifier_type', 40);
            $table->string('identifier_value');
            $table->boolean('is_default')->default(false);
            $table->unsignedTinyInteger('default_marker')->nullable();
            $table->timestampsTz();

            $table->unique(['user_id', 'default_marker'], 'uq_payout_destination_default');
            $table->index(['user_id', 'is_default'], 'idx_payout_destinations_user');
        });

        Schema::create('auction_seller_payouts', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('auction_id')->constrained('auctions')->restrictOnDelete();
            $table->foreignId('settlement_id')->constrained('auction_settlements')->restrictOnDelete();
            $table->foreignId('seller_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 40)->default('pending');
            $table->unsignedBigInteger('winning_amount_minor');
            $table->unsignedBigInteger('platform_fee_minor')->default(0);
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency_code', 3);
            $table->foreignId('destination_id')->nullable()->constrained('payout_destinations')->nullOnDelete();
            $table->string('recipient_name')->nullable();
            $table->string('identifier_type', 40)->nullable();
            $table->string('identifier_value')->nullable();
            $table->string('payout_method', 60)->nullable();
            $table->string('transfer_reference')->nullable();
            $table->text('note')->nullable();
            $table->string('proof_disk')->nullable();
            $table->string('proof_path')->nullable();
            $table->string('proof_mime_type', 100)->nullable();
            $table->unsignedBigInteger('proof_size_bytes')->nullable();
            $table->string('hold_reason')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestampTz('held_at')->nullable();
            $table->timestampTz('processing_started_at')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique('settlement_id', 'uq_seller_payout_settlement');
            $table->index(['status', 'created_at'], 'idx_seller_payouts_status_created');
            $table->index(['seller_id', 'status'], 'idx_seller_payouts_seller_status');
            $table->index('auction_id', 'idx_seller_payouts_auction');
            $table->index('transfer_reference', 'idx_seller_payouts_reference');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auction_seller_payouts');
        Schema::dropIfExists('payout_destinations');
    }
};
