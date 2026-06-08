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

            // اليوزر اللي دفع
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // المبلغ المدفوع (التأمين)
            $table->decimal('amount', 15, 2)->nullable()->comment('قيمة التأمين المدفوعة');

            // الحالة الأساسية للدفع
            $table->boolean('paid')->default(false);
            $table->timestamp('paid_at')->nullable();
            $table->string('transaction_id')->nullable();

            // حالة التأمين (للتأمينات الخاصة بالمزايدات)
            $table->enum('status', ['held', 'refunded', 'forfeited', 'applied_to_payment'])
                  ->default('held')
                  ->comment('حالة التأمين بعد الدفع: محتجز، مسترد، مصادر، محول للدفع');
            $table->timestamp('processed_at')->nullable();

            // Polymorphic relation: مورف يحدد نوع الدفع
            // - App\Models\Auction    → تأمين المعلن (إعلان مزاد)
            // - App\Models\AuctionBid → تأمين المزايد (مزايدة على مزاد)
            $table->morphs('depositable');

            $table->timestamps();

            // مؤشرات للبحث السريع
            $table->index(['user_id', 'paid'], 'idx_user_deposit_paid');
            $table->index(['depositable_type', 'depositable_id'], 'idx_depositable');
            $table->index('transaction_id', 'idx_transaction');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auction_deposits');
    }
};