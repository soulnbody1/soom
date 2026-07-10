<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('auction_deposits')) {
            return;
        }

        Schema::create('auction_deposits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->morphs('depositable');
            $table->boolean('paid')->default(false);
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('transaction_id')->nullable();
            $table->decimal('amount', 15, 2)->nullable();
            $table->enum('deposit_status', ['held', 'refunded', 'forfeited', 'applied_to_payment'])->default('held');
            $table->timestamp('processed_at')->nullable();
            $table->enum('deposit_type', ['advertiser', 'bidder'])->default('bidder');
            $table->timestamps();

            $table->index('user_id', 'idx_deposit_user');
            $table->index(['user_id', 'deposit_status'], 'idx_user_deposit_status');
            $table->index(['deposit_type', 'deposit_status'], 'idx_deposit_type_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auction_deposits');
    }
};
