<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payment_slips')) {
            return;
        }

        Schema::create('payment_slips', function (Blueprint $table) {
            $table->id();
            $table->string('image_path')->comment('صورة إيصال الدفع');
            $table->foreignId('payment_method_id')->constrained('payment_methods')->cascadeOnDelete();
            $table->decimal('amount', 15, 2)->comment('المبلغ المدفوع');
            $table->nullableMorphs('payable'); // payable_id + payable_type (Auction / AuctionBid)
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_slips');
    }
};
