<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // إنشاء جدول إعدادات التأمين
        Schema::create('auctions_configurations', function (Blueprint $table) {
            $table->id();
            $table->enum('deposit_type', ['fixed', 'percentage'])->default('percentage');
            $table->decimal('amount', 15, 2)->comment('قيمة التأمين (نسبة مئوية أو مبلغ ثابت)');
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->integer('duration_days')->default(7);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->timestamps();
        });

        // حذف الأعمدة القديمة من جدول المزادات
        Schema::table('auctions', function (Blueprint $table) {
            $table->dropColumn(['deposit_type', 'deposit_fixed_amount', 'deposit_percentage']);
        });

        // إضافة عمود deposit_amount الجديد
        Schema::table('auctions', function (Blueprint $table) {
            $table->decimal('deposit_amount', 15, 2)->nullable()->after('min_accept_price');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auctions_configurations');

        Schema::table('auctions', function (Blueprint $table) {
            $table->dropColumn('deposit_amount');
        });

        Schema::table('auctions', function (Blueprint $table) {
            $table->enum('deposit_type', ['fixed', 'percentage'])->nullable();
            $table->decimal('deposit_fixed_amount', 15, 2)->nullable();
            $table->decimal('deposit_percentage', 5, 2)->nullable();
        });
    }
};