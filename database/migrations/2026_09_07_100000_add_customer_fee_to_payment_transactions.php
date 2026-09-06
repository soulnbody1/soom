<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table): void {
            if (! Schema::hasColumn('payment_transactions', 'customer_fee_minor')) {
                $table->unsignedBigInteger('customer_fee_minor')->default(0)->after('currency_code');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table): void {
            $table->dropColumn('customer_fee_minor');
        });
    }
};
