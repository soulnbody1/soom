<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payment_billing_references')) {
            return;
        }

        Schema::create('payment_billing_references', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('provider', 60);
            $table->string('reference', 40);
            $table->timestampTz('allocated_at');
            $table->timestampsTz();

            // The reference identifies exactly one payer within a provider...
            $table->unique(['provider', 'reference'], 'uq_billing_reference_provider');
            // ...and a payer keeps the same reference forever within that provider.
            $table->unique(['user_id', 'provider'], 'uq_billing_reference_user_provider');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_billing_references');
    }
};
