<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table): void {
            if (! Schema::hasColumn('payment_methods', 'fee_basis')) {
                $table->string('fee_basis', 20)->nullable()->after('max_amount_minor');
            }
            if (! Schema::hasColumn('payment_methods', 'fee_tiers')) {
                $table->json('fee_tiers')->nullable()->after('fee_basis');
            }
            if (! Schema::hasColumn('payment_methods', 'provider_purpose_codes')) {
                $table->json('provider_purpose_codes')->nullable()->after('fee_tiers');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table): void {
            $table->dropColumn(['fee_basis', 'fee_tiers', 'provider_purpose_codes']);
        });
    }
};
