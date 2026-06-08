<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auction_deposits', function (Blueprint $table) {
            $table->id();

            // Morph: يحدد هل الدفع لمعلن (Auction) أم لمزايد (AuctionBid)
            $table->morphs('depositable');

            // اليوزر اللي دفع
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // المزاد المرتبط (مطلوب في الحالتين)
            $table->foreignId('auction_id')->constrained('auctions')->cascadeOnDelete();

            // البيد المرتبط (فقط لو الدفع لمزايد - nullable لو المعلن)
            $table->foreignId('bid_id')->nullable()->constrained('auction_bids')->nullOnDelete();

            // بيانات التأمين (بديل الخانات القديمة)
            $table->boolean('is_paid')->default(false)->comment('هل تم الدفع؟');
            $table->timestamp('paid_at')->nullable()->comment('تاريخ الدفع');
            $table->string('transaction_id')->nullable()->comment('رقم المعاملة');

            $table->timestamps();

            // Indexes
            $table->index(['depositable_type', 'depositable_id'], 'idx_depositable');
            $table->index(['user_id', 'is_paid'], 'idx_user_deposit_status');
            $table->index('auction_id', 'idx_deposit_auction');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auction_deposits');
    }
};