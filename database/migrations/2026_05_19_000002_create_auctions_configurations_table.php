<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('auctions_configurations')) {
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
        }

        if (! Schema::hasColumn('auctions', 'deposit_amount')) {
            Schema::table('auctions', function (Blueprint $table) {
                $table->decimal('deposit_amount', 15, 2)->nullable()->after('min_accept_price');
            });
        }

        if (
            Schema::hasColumn('auctions', 'deposit_type')
            && Schema::hasColumn('auctions', 'deposit_fixed_amount')
            && Schema::hasColumn('auctions', 'deposit_percentage')
        ) {
            DB::table('auctions')
                ->whereNull('deposit_amount')
                ->update([
                    'deposit_amount' => DB::raw(
                        "CASE
                            WHEN deposit_type = 'fixed' THEN deposit_fixed_amount
                            WHEN deposit_type = 'percentage' THEN ROUND(min_accept_price * deposit_percentage / 100, 2)
                            ELSE NULL
                        END"
                    ),
                ]);
        }

        $oldColumns = array_values(array_filter([
            Schema::hasColumn('auctions', 'deposit_type') ? 'deposit_type' : null,
            Schema::hasColumn('auctions', 'deposit_fixed_amount') ? 'deposit_fixed_amount' : null,
            Schema::hasColumn('auctions', 'deposit_percentage') ? 'deposit_percentage' : null,
        ]));

        if ($oldColumns !== []) {
            Schema::table('auctions', function (Blueprint $table) use ($oldColumns) {
                $table->dropColumn($oldColumns);
            });
        }
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
