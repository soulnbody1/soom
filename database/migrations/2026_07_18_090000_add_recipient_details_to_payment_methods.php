<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->string('recipient_name')->nullable()->after('code');
            $table->string('identifier_type', 40)->nullable()->after('recipient_name');
            $table->string('identifier_value')->nullable()->after('identifier_type');
        });
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropColumn(['recipient_name', 'identifier_type', 'identifier_value']);
        });
    }
};
