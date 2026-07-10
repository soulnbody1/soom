<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $statColumns = array_values(array_filter([
            Schema::hasColumn('auctions', 'bids_count') ? 'bids_count' : null,
            Schema::hasColumn('auctions', 'views_count') ? 'views_count' : null,
            Schema::hasColumn('auctions', 'unique_bidders_count') ? 'unique_bidders_count' : null,
        ]));

        if ($statColumns !== []) {
            Schema::table('auctions', function (Blueprint $table) use ($statColumns) {
                $table->dropColumn($statColumns);
            });
        }

        // إنشاء جدول مشاهدة المزادات (زى ad_views)
        if (! Schema::hasTable('auction_views')) {
            Schema::create('auction_views', function (Blueprint $table) {
                $table->id();
                $table->foreignId('auction_id')->constrained('auctions')->onDelete('cascade');
                $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');
                $table->string('ip_address', 45)->nullable();
                $table->timestamp('viewed_at')->nullable();
                $table->timestamps();

                $table->index(['auction_id', 'user_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('auction_views');

        Schema::table('auctions', function (Blueprint $table) {
            $table->integer('bids_count')->default(0);
            $table->integer('views_count')->default(0);
            $table->integer('unique_bidders_count')->default(0);
        });
    }
};
