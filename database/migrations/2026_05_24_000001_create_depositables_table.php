<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // جدول موحد للتأمينات - يغطي تأمين المعلن (رسوم الإعلان عن المزاد) وتأمين المزايد
        Schema::create('depositables', function (Blueprint $table) {
            $table->id();
            
            // اليوزر اللي دفع التأمين
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            
            // Polymorphic relation: morph to Auction (تأمين المعلن) or AuctionBid (تأمين المزايد)
            $table->morphs('depositable');
            
            // بيانات الدفع
            $table->boolean('paid')->default(false);
            $table->timestamp('paid_at')->nullable();
            $table->string('transaction_id')->nullable();
            
            // المبلغ المدفوع
            $table->decimal('amount', 15, 2)->default(0);
            
            // حالة التأمين (للمزايدات)
            $table->enum('status', ['held', 'refunded', 'forfeited', 'applied_to_payment'])
                  ->default('held')->nullable();
            $table->timestamp('processed_at')->nullable();
            
            // مؤشرات للبحث السريع
            $table->index(['user_id', 'paid'], 'idx_depositable_user_paid');
            $table->index(['depositable_type', 'depositable_id'], 'idx_depositable_morph');
            
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('depositables');
    }
};