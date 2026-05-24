<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // جدول موحد لتأمينات المزادات (تأمين المعلن + تأمين المزايد)
        Schema::create('auction_deposits', function (Blueprint $table) {
            $table->id();

            // اليوزر اللي دفع التأمين
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');

            // Polymorphic relation: يشير إما للمزاد (تأمين معلن) أو للمزايدة (تأمين مزايد)
            $table->morphs('depositable');

            // حالة الدفع
            $table->boolean('paid')->default(false);
            $table->timestamp('paid_at')->nullable();
            $table->string('transaction_id')->nullable();
            $table->decimal('amount', 15, 2)->nullable();

            // حالة التأمين (للمزايدين)
            $table->enum('deposit_status', ['held', 'refunded', 'forfeited', 'applied_to_payment'])
                  ->default('held');
            $table->timestamp('processed_at')->nullable();

            // نوع التأمين (للمعلن أو المزايد)
            $table->enum('deposit_type', ['advertiser', 'bidder'])->default('bidder');

            $table->timestamps();

            // مؤشرات للبحث السريع
            $table->index(['depositable_id', 'depositable_type'], 'idx_depositable');
            $table->index('user_id', 'idx_deposit_user');
            $table->index(['user_id', 'deposit_status'], 'idx_user_deposit_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auction_deposits');
    }
};