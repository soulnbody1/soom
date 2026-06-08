<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // جدول موحد للتأمينات - يغطي تأمين المعلن (رسوم الإعلان عن المزاد) وتأمين المزايدين
        // يستخدم Polymorphic Relation للربط مع Auction (تأمين المعلن) أو AuctionBid (تأمين المزايد)
        Schema::create('depositables', function (Blueprint $table) {
            $table->id();
            
            // المستخدم الذي دفع التأمين (معلن أو مزايد)
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            
            // Polymorphic relation: depositable = Auction (للمعلن) أو AuctionBid (للمزايد)
            $table->morphs('depositable');
            
            // المبلغ المدفوع
            $table->decimal('amount', 15, 2)->default(0)->comment('مبلغ التأمين المدفوع');
            
            // حالة الدفع
            $table->boolean('paid')->default(false)->comment('هل تم الدفع');
            $table->timestamp('paid_at')->nullable()->comment('تاريخ الدفع');
            $table->string('transaction_id')->nullable()->comment('رقم المعاملة');
            
            // حالة التأمين (للمزايدات)
            $table->enum('status', ['held', 'refunded', 'forfeited', 'applied_to_payment'])
                  ->default('held')->comment('حالة التأمين: محتجز / مسترد / مصادرة / مطبق على الدفع');
            $table->timestamp('processed_at')->nullable()->comment('تاريخ معالجة التأمين');
            
            $table->timestamps();
            
            // مؤشرات للبحث السريع
            $table->index(['depositable_type', 'depositable_id'], 'idx_depositable');
            $table->index(['user_id', 'paid'], 'idx_user_paid');
            $table->index(['user_id', 'status'], 'idx_user_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('depositables');
    }
};