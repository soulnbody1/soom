<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'auction_permissions')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->json('auction_permissions')->nullable()->after('role');
            });
        }

        if (! Schema::hasTable('payment_submissions')) {
            return;
        }

        Schema::table('payment_submissions', function (Blueprint $table): void {
            if (! Schema::hasColumn('payment_submissions', 'overridden_by')) {
                $table->foreignId('overridden_by')
                    ->nullable()
                    ->after('reviewed_by')
                    ->constrained('users')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('payment_submissions', 'overridden_at')) {
                $table->timestampTz('overridden_at')->nullable()->after('reviewed_at');
            }

            if (! Schema::hasColumn('payment_submissions', 'override_reason')) {
                $table->text('override_reason')->nullable()->after('review_note');
            }

            if (! Schema::hasColumn('payment_submissions', 'original_deadline')) {
                $table->timestampTz('original_deadline')->nullable()->after('overridden_at');
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('payment_submissions')) {
            Schema::table('payment_submissions', function (Blueprint $table): void {
                if (Schema::hasColumn('payment_submissions', 'overridden_by')) {
                    $table->dropConstrainedForeignId('overridden_by');
                }

                foreach (['original_deadline', 'override_reason', 'overridden_at'] as $column) {
                    if (Schema::hasColumn('payment_submissions', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('users') && Schema::hasColumn('users', 'auction_permissions')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn('auction_permissions');
            });
        }
    }
};
