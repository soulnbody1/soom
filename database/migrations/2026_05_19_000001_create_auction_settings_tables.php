<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // جدول القواعد والشروط
        Schema::create('auction_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name')->comment('نص القاعدة أو الشرط');
            $table->timestamps();
        });

        // جدول طرق الدفع
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('name')->comment('اسم طريقة الدفع');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
        Schema::dropIfExists('auction_rules');
    }
};