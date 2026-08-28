<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refund_transactions', function (Blueprint $table): void {
            if (! Schema::hasColumn('refund_transactions', 'destination_id')) {
                $table->foreignId('destination_id')
                    ->nullable()
                    ->after('user_id')
                    ->constrained('payout_destinations')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('refund_transactions', 'recipient_name')) {
                $table->string('recipient_name')->nullable()->after('destination_id');
            }
            if (! Schema::hasColumn('refund_transactions', 'identifier_type')) {
                $table->string('identifier_type', 40)->nullable()->after('recipient_name');
            }
            if (! Schema::hasColumn('refund_transactions', 'identifier_value')) {
                $table->string('identifier_value')->nullable()->after('identifier_type');
            }
            if (! Schema::hasColumn('refund_transactions', 'proof_disk')) {
                $table->string('proof_disk')->nullable()->after('manual_confirmation_reason');
            }
            if (! Schema::hasColumn('refund_transactions', 'proof_path')) {
                $table->string('proof_path')->nullable()->after('proof_disk');
            }
            if (! Schema::hasColumn('refund_transactions', 'proof_mime_type')) {
                $table->string('proof_mime_type', 100)->nullable()->after('proof_path');
            }
            if (! Schema::hasColumn('refund_transactions', 'proof_size_bytes')) {
                $table->unsignedBigInteger('proof_size_bytes')->nullable()->after('proof_mime_type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('refund_transactions', function (Blueprint $table): void {
            if (Schema::hasColumn('refund_transactions', 'destination_id')) {
                $table->dropConstrainedForeignId('destination_id');
            }

            foreach ([
                'recipient_name',
                'identifier_type',
                'identifier_value',
                'proof_disk',
                'proof_path',
                'proof_mime_type',
                'proof_size_bytes',
            ] as $column) {
                if (Schema::hasColumn('refund_transactions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
