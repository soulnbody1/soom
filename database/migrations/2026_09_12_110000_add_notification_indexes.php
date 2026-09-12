<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MORPHS_INDEX = 'notifications_notifiable_type_notifiable_id_index';

    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $this->addIndex(
                $table,
                ['notifiable_type', 'notifiable_id', 'created_at'],
                'idx_notifications_notifiable_created'
            );

            $this->addIndex(
                $table,
                ['notifiable_type', 'notifiable_id', 'read_at', 'created_at'],
                'idx_notifications_notifiable_unread'
            );
        });

        $this->dropIndexIfExists(self::MORPHS_INDEX);
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $this->addIndex($table, ['notifiable_type', 'notifiable_id'], self::MORPHS_INDEX);

            foreach (['idx_notifications_notifiable_created', 'idx_notifications_notifiable_unread'] as $index) {
                if ($this->indexExists($index)) {
                    $table->dropIndex($index);
                }
            }
        });
    }

    private function addIndex(Blueprint $table, array $columns, string $name): void
    {
        if (! $this->indexExists($name)) {
            $table->index($columns, $name);
        }
    }

    private function dropIndexIfExists(string $name): void
    {
        if ($this->driver() !== 'mysql' || ! $this->indexExists($name)) {
            return;
        }

        DB::statement("ALTER TABLE `notifications` DROP INDEX `{$name}`");
    }

    private function indexExists(string $name): bool
    {
        if (! Schema::hasTable('notifications')) {
            return false;
        }

        foreach (Schema::getIndexes('notifications') as $index) {
            if (strcasecmp((string) $index['name'], $name) === 0) {
                return true;
            }
        }

        return false;
    }

    private function driver(): string
    {
        return Schema::getConnection()->getDriverName();
    }
};
