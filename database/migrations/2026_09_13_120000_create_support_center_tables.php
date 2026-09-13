<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_categories', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('code', 50)->unique();
            $table->string('name_ar', 100);
            $table->string('name_en', 100);
            $table->string('default_priority', 20)->default('normal');
            $table->unsignedInteger('first_response_minutes')->default(240);
            $table->unsignedInteger('resolution_minutes')->default(2880);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'sort_order'], 'idx_support_categories_active_order');
        });

        Schema::create('support_tickets', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('reference_number', 32)->unique();
            $table->foreignId('requester_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('category_id')->constrained('support_categories')->restrictOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('subject', 160);
            $table->string('status', 30)->default('new');
            $table->string('priority', 20)->default('normal');
            $table->string('context_type', 50)->nullable();
            $table->string('context_id', 64)->nullable();
            $table->unsignedBigInteger('last_message_id')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('first_response_due_at')->nullable();
            $table->timestamp('resolution_due_at')->nullable();
            $table->timestamp('first_responded_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('reopened_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->index(['requester_id', 'last_message_at'], 'idx_support_tickets_requester_last');
            $table->index(['status', 'priority', 'last_message_at'], 'idx_support_tickets_queue');
            $table->index(['assigned_to', 'status', 'last_message_at'], 'idx_support_tickets_assignee');
            $table->index(['first_response_due_at', 'first_responded_at'], 'idx_support_tickets_first_sla');
            $table->index(['resolution_due_at', 'resolved_at'], 'idx_support_tickets_resolution_sla');
        });

        Schema::create('support_messages', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('author_type', 20);
            $table->string('visibility', 20)->default('public');
            $table->text('body');
            $table->uuid('client_message_id')->nullable();
            $table->timestamps();

            $table->unique(['author_id', 'client_message_id'], 'uq_support_messages_author_client');
            $table->index(['ticket_id', 'visibility', 'id'], 'idx_support_messages_ticket_visibility');
        });

        Schema::table('support_tickets', function (Blueprint $table): void {
            $table->foreign('last_message_id')->references('id')->on('support_messages')->nullOnDelete();
        });

        Schema::create('support_ticket_reads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('last_read_message_id')->nullable();
            $table->timestamp('read_at');
            $table->timestamps();

            $table->unique(['ticket_id', 'user_id']);
        });

        Schema::create('support_ticket_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 50);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['ticket_id', 'id'], 'idx_support_events_ticket');
        });

        $now = now();
        DB::table('support_categories')->insert([
            ['public_id' => (string) Str::ulid(), 'code' => 'account', 'name_ar' => 'الحساب والتحقق', 'name_en' => 'Account & verification', 'default_priority' => 'normal', 'first_response_minutes' => 240, 'resolution_minutes' => 2880, 'is_active' => true, 'sort_order' => 10, 'created_at' => $now, 'updated_at' => $now],
            ['public_id' => (string) Str::ulid(), 'code' => 'auctions', 'name_ar' => 'المزادات والمزايدات', 'name_en' => 'Auctions & bidding', 'default_priority' => 'normal', 'first_response_minutes' => 180, 'resolution_minutes' => 1440, 'is_active' => true, 'sort_order' => 20, 'created_at' => $now, 'updated_at' => $now],
            ['public_id' => (string) Str::ulid(), 'code' => 'payments', 'name_ar' => 'المدفوعات والاسترداد', 'name_en' => 'Payments & refunds', 'default_priority' => 'high', 'first_response_minutes' => 60, 'resolution_minutes' => 720, 'is_active' => true, 'sort_order' => 30, 'created_at' => $now, 'updated_at' => $now],
            ['public_id' => (string) Str::ulid(), 'code' => 'technical', 'name_ar' => 'مشكلة تقنية', 'name_en' => 'Technical issue', 'default_priority' => 'normal', 'first_response_minutes' => 240, 'resolution_minutes' => 2880, 'is_active' => true, 'sort_order' => 40, 'created_at' => $now, 'updated_at' => $now],
            ['public_id' => (string) Str::ulid(), 'code' => 'other', 'name_ar' => 'استفسار آخر', 'name_en' => 'Other inquiry', 'default_priority' => 'normal', 'first_response_minutes' => 360, 'resolution_minutes' => 4320, 'is_active' => true, 'sort_order' => 50, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_events');
        Schema::dropIfExists('support_ticket_reads');
        Schema::table('support_tickets', function (Blueprint $table): void {
            $table->dropForeign(['last_message_id']);
        });
        Schema::dropIfExists('support_messages');
        Schema::dropIfExists('support_tickets');
        Schema::dropIfExists('support_categories');
    }
};
