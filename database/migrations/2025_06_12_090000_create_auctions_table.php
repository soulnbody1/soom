<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // جدول المزادات (مستقل تماماً عن الإعلانات)
        Schema::create('auctions', function (Blueprint $table) {
            $table->id();
            
            // بيانات صاحب المزاد والتصنيف
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('category_id')->constrained()->onDelete('cascade');
            
            // بيانات السلعة
            $table->string('title');
            $table->text('description');
            
            // الموقع (الدولة مطلوبة، الباقي اختياري)
            $table->foreignId('country_id')->constrained('countries')->onDelete('cascade');
            $table->foreignId('state_id')->nullable()->constrained('states')->onDelete('cascade');
            $table->foreignId('city_id')->nullable()->constrained('cities')->onDelete('cascade');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            
            // الأسعار
            $table->decimal('starting_price', 15, 2);
            $table->decimal('current_bid', 15, 2)->nullable();
            $table->decimal('min_accept_price', 15, 2)->default(0);
            
            // التأمين (مرن - ثابت أو نسبة) - يتم تحديده من الأدمن لاحقاً
            $table->enum('deposit_type', ['fixed', 'percentage'])->nullable();
            $table->decimal('deposit_fixed_amount', 15, 2)->nullable();
            $table->decimal('deposit_percentage', 5, 2)->nullable();
            
            // الحالة والتوقيت
            $table->enum('status', ['draft', 'pending_payment', 'active', 'extended', 'closed', 'completed', 'cancelled'])
                  ->default('draft');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at');
            $table->timestamp('original_ends_at')->nullable();
            $table->foreignId('winner_id')->nullable()->constrained('users');
            $table->integer('duration_days')->default(0);
            $table->boolean('terms_accepted')->default(false);
            
            // دفع التأمين (للمعلن)
            $table->boolean('advertiser_deposit_paid')->default(false);
            $table->timestamp('advertiser_deposit_paid_at')->nullable();
            $table->string('advertiser_deposit_transaction_id')->nullable();
            
            // الإحصائيات
            $table->integer('bids_count')->default(0);
            $table->integer('views_count')->default(0);
            $table->integer('unique_bidders_count')->default(0);
            
            // مؤشرات للبحث السريع
            $table->index(['status', 'ends_at'], 'idx_status_ends');
            $table->index('user_id', 'idx_auction_user');
            $table->index('category_id', 'idx_auction_category');
            
            $table->timestamps();
        });
        
        // جدول صور المزادات
        Schema::create('auction_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('auction_id')->constrained()->onDelete('cascade');
            $table->string('image_path');
            $table->integer('order')->default(0);
            $table->timestamps();
        });
        
        // جدول المزايدات
        Schema::create('auction_bids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('auction_id')->constrained('auctions')->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            
            // قيمة المزايدة
            $table->decimal('amount', 15, 2);
            $table->boolean('is_winning')->default(false);
            $table->timestamp('winning_at')->nullable();
            
            // تأمين المزايد
            $table->boolean('deposit_paid')->default(false);
            $table->timestamp('deposit_paid_at')->nullable();
            $table->string('deposit_transaction_id')->nullable();
            
            // حالة التأمين
            $table->enum('deposit_status', ['held', 'refunded', 'forfeited', 'applied_to_payment'])
                  ->default('held');
            $table->timestamp('deposit_processed_at')->nullable();
            
            // الموافقة على الشروط والأحكام
            $table->boolean('terms_accepted')->default(false);
            
            // مؤشرات للبحث السريع
            $table->index(['auction_id', 'amount'], 'idx_bid_auction_amount');
            $table->index(['user_id', 'deposit_status'], 'idx_user_deposit_status');
            $table->index(['auction_id', 'is_winning'], 'idx_auction_winning');
            
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auction_bids');
        Schema::dropIfExists('auction_images');
        Schema::dropIfExists('auctions');
    }
};
