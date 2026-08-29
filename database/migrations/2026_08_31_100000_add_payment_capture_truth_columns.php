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
            if (! Schema::hasColumn('payment_transactions', 'captured_amount_minor')) {
                $table->unsignedBigInteger('captured_amount_minor')->nullable()->after('currency_code');
            }
            if (! Schema::hasColumn('payment_transactions', 'captured_currency_code')) {
                $table->char('captured_currency_code', 3)->nullable()->after('captured_amount_minor');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table): void {
            $table->dropColumn(['captured_amount_minor', 'captured_currency_code']);
        });
    }
};
