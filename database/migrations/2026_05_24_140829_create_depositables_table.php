<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('depositables', function (Blueprint $table) {
            $table->id();
            
            // المستخدم الذي دفع التأمين
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            
            // حالة الدفع
            $table->boolean('paid')->default(false);
            $table->timestamp('paid_at')->nullable();
            $table->string('transaction_id')->nullable();
            
            // Polymorphic relation
            $table->morphs('depositable');
            
            $table->timestamps();
            
            // Indexes
            $table->index(['user_id', 'paid'], 'idx_depositable_user_paid');
            $table->index(['depositable_type', 'depositable_id', 'paid'], 'idx_depositable_morph_paid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('depositables');
    }
};