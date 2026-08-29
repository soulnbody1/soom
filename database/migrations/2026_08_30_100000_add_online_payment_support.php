<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->relaxPaymentSubmissionLink();

        Schema::table('payment_transactions', function (Blueprint $table): void {
            if (! Schema::hasColumn('payment_transactions', 'payment_method_id')) {
                $table->foreignId('payment_method_id')
                    ->nullable()
                    ->after('user_id')
                    ->constrained('payment_methods')
                    ->restrictOnDelete();
            }
            if (! Schema::hasColumn('payment_transactions', 'failure_code')) {
                $table->string('failure_code', 80)->nullable()->after('status');
            }
            if (! Schema::hasColumn('payment_transactions', 'provider_fee_minor')) {
                $table->unsignedBigInteger('provider_fee_minor')->nullable()->after('provider_payload');
            }
            if (! Schema::hasColumn('payment_transactions', 'settlement_reference')) {
                $table->string('settlement_reference')->nullable()->after('provider_fee_minor');
            }
            if (! Schema::hasColumn('payment_transactions', 'settled_at')) {
                $table->timestampTz('settled_at')->nullable()->after('settlement_reference');
            }
            if (! Schema::hasColumn('payment_transactions', 'checkout_instruction')) {
                $table->json('checkout_instruction')->nullable()->after('provider_payload');
            }
            if (! Schema::hasColumn('payment_transactions', 'checkout_claimed_at')) {
                $table->timestampTz('checkout_claimed_at')->nullable()->after('checkout_instruction');
            }
            if (! Schema::hasColumn('payment_transactions', 'expires_at')) {
                $table->timestampTz('expires_at')->nullable()->after('processed_at');
            }
        });

        Schema::table('payment_transactions', function (Blueprint $table): void {
            $table->index(['provider', 'status', 'expires_at'], 'idx_payment_transactions_reconcile');
            $table->index(['status', 'created_at'], 'idx_payment_transactions_status_created');
        });

        Schema::table('payment_methods', function (Blueprint $table): void {
            if (! Schema::hasColumn('payment_methods', 'channel')) {
                $table->string('channel', 20)->default('manual')->after('code');
            }
            if (! Schema::hasColumn('payment_methods', 'rail')) {
                $table->string('rail', 20)->default('transfer')->after('channel');
            }
            if (! Schema::hasColumn('payment_methods', 'provider_code')) {
                $table->string('provider_code', 60)->nullable()->after('rail');
            }
            if (! Schema::hasColumn('payment_methods', 'is_sandbox')) {
                $table->boolean('is_sandbox')->default(false)->after('provider_code');
            }
            if (! Schema::hasColumn('payment_methods', 'display_order')) {
                $table->unsignedInteger('display_order')->default(0)->after('is_active');
            }
            if (! Schema::hasColumn('payment_methods', 'allowed_purposes')) {
                $table->json('allowed_purposes')->nullable()->after('display_order');
            }
            if (! Schema::hasColumn('payment_methods', 'country_codes')) {
                $table->json('country_codes')->nullable()->after('allowed_purposes');
            }
            if (! Schema::hasColumn('payment_methods', 'currency_codes')) {
                $table->json('currency_codes')->nullable()->after('country_codes');
            }
            if (! Schema::hasColumn('payment_methods', 'min_amount_minor')) {
                $table->unsignedBigInteger('min_amount_minor')->nullable()->after('currency_codes');
            }
            if (! Schema::hasColumn('payment_methods', 'max_amount_minor')) {
                $table->unsignedBigInteger('max_amount_minor')->nullable()->after('min_amount_minor');
            }
        });

        Schema::table('payment_methods', function (Blueprint $table): void {
            $table->index(['channel', 'is_active', 'display_order'], 'idx_payment_methods_channel_active');
        });

        if (! Schema::hasTable('payment_provider_events')) {
            Schema::create('payment_provider_events', function (Blueprint $table): void {
                $table->id();
                $table->char('public_id', 26)->unique();
                $table->string('provider', 60);
                $table->string('event_id', 190);
                $table->string('event_type', 80);
                $table->foreignId('payment_transaction_id')->nullable()->constrained('payment_transactions')->nullOnDelete();
                $table->string('provider_transaction_id')->nullable();
                $table->boolean('signature_verified')->default(false);
                $table->json('payload_redacted')->nullable();
                $table->timestampTz('received_at');
                $table->timestampTz('processed_at')->nullable();
                $table->string('process_error', 190)->nullable();
                $table->timestampsTz();

                $table->unique(['provider', 'event_id'], 'uq_payment_provider_event');
                $table->index(['provider', 'processed_at'], 'idx_provider_events_unprocessed');
                $table->index('payment_transaction_id', 'idx_provider_events_transaction');
            });
        }

        Schema::table('refund_transactions', function (Blueprint $table): void {
            if (! Schema::hasColumn('refund_transactions', 'provider_fee_minor')) {
                $table->unsignedBigInteger('provider_fee_minor')->nullable()->after('applied_refund_amount_minor');
            }
        });

        DB::table('payment_methods')->whereNull('channel')->update(['channel' => 'manual']);
        DB::table('payment_methods')->whereNull('rail')->update(['rail' => 'transfer']);
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_provider_events');

        Schema::table('refund_transactions', function (Blueprint $table): void {
            if (Schema::hasColumn('refund_transactions', 'provider_fee_minor')) {
                $table->dropColumn('provider_fee_minor');
            }
        });

        Schema::table('payment_methods', function (Blueprint $table): void {
            $table->dropIndex('idx_payment_methods_channel_active');
            $table->dropColumn([
                'channel',
                'rail',
                'provider_code',
                'is_sandbox',
                'display_order',
                'allowed_purposes',
                'country_codes',
                'currency_codes',
                'min_amount_minor',
                'max_amount_minor',
            ]);
        });

        Schema::table('payment_transactions', function (Blueprint $table): void {
            $table->dropIndex('idx_payment_transactions_reconcile');
            $table->dropIndex('idx_payment_transactions_status_created');
        });

        Schema::table('payment_transactions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('payment_method_id');
            $table->dropColumn([
                'failure_code',
                'checkout_instruction',
                'checkout_claimed_at',
                'provider_fee_minor',
                'settlement_reference',
                'settled_at',
                'expires_at',
            ]);
        });
    }

    private function relaxPaymentSubmissionLink(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE payment_transactions DROP FOREIGN KEY payment_transactions_payment_submission_id_foreign');
            DB::statement('ALTER TABLE payment_transactions MODIFY payment_submission_id BIGINT UNSIGNED NULL');
            DB::statement('ALTER TABLE payment_transactions ADD CONSTRAINT payment_transactions_payment_submission_id_foreign FOREIGN KEY (payment_submission_id) REFERENCES payment_submissions (id)');

            return;
        }

        Schema::table('payment_transactions', function (Blueprint $table): void {
            $table->unsignedBigInteger('payment_submission_id')->nullable()->change();
        });
    }
};
