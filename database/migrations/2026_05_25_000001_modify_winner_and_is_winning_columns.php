<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('auctions', 'winner_id')) {
            try {
                Schema::table('auctions', function (Blueprint $table) {
                    $table->dropForeign(['winner_id']);
                });
            } catch (\Throwable) {
                // The foreign key may already be absent in partially migrated databases.
            }
        }

        if (! Schema::hasColumn('auction_bids', 'is_winning')) {
            Schema::table('auction_bids', function (Blueprint $table) {
                $table->unsignedBigInteger('is_winning')->nullable()->after('amount');
            });

            $this->addWinningForeignKeyAndIndex();

            return;
        }

        $columnType = Schema::getColumnType('auction_bids', 'is_winning');

        if ($columnType !== 'tinyint' && $columnType !== 'boolean') {
            $this->addWinningForeignKeyAndIndex();

            return;
        }

        if (! Schema::hasColumn('auction_bids', 'is_winning_user_id')) {
            Schema::table('auction_bids', function (Blueprint $table) {
                $table->unsignedBigInteger('is_winning_user_id')->nullable()->after('is_winning');
            });
        }

        DB::table('auction_bids')
            ->where('is_winning', true)
            ->update(['is_winning_user_id' => DB::raw('user_id')]);

        try {
            Schema::table('auction_bids', function (Blueprint $table) {
                $table->dropIndex('idx_auction_winning');
            });
        } catch (\Throwable) {
            // The index may already be absent in partially migrated databases.
        }

        try {
            Schema::table('auction_bids', function (Blueprint $table) {
                $table->dropForeign(['is_winning']);
            });
        } catch (\Throwable) {
            // The old boolean column did not have a foreign key.
        }

        Schema::table('auction_bids', function (Blueprint $table) {
            $table->dropColumn('is_winning');
        });

        Schema::table('auction_bids', function (Blueprint $table) {
            $table->unsignedBigInteger('is_winning')->nullable()->after('amount');
        });

        DB::table('auction_bids')->update([
            'is_winning' => DB::raw('is_winning_user_id'),
        ]);

        Schema::table('auction_bids', function (Blueprint $table) {
            $table->dropColumn('is_winning_user_id');
        });

        $this->addWinningForeignKeyAndIndex();
    }

    public function down(): void
    {
        try {
            Schema::table('auction_bids', function (Blueprint $table) {
                $table->dropForeign(['is_winning']);
            });
        } catch (\Throwable) {
            //
        }

        try {
            Schema::table('auction_bids', function (Blueprint $table) {
                $table->dropIndex('idx_auction_winning');
            });
        } catch (\Throwable) {
            //
        }

        if (Schema::hasColumn('auction_bids', 'is_winning')) {
            Schema::table('auction_bids', function (Blueprint $table) {
                $table->dropColumn('is_winning');
            });
        }

        Schema::table('auction_bids', function (Blueprint $table) {
            $table->boolean('is_winning')->default(false)->after('amount');
            $table->index(['auction_id', 'is_winning'], 'idx_auction_winning');
        });

        try {
            Schema::table('auctions', function (Blueprint $table) {
                $table->foreign('winner_id')->references('id')->on('users');
            });
        } catch (\Throwable) {
            //
        }
    }

    private function addWinningForeignKeyAndIndex(): void
    {
        try {
            Schema::table('auction_bids', function (Blueprint $table) {
                $table->foreign('is_winning')->references('id')->on('users');
            });
        } catch (\Throwable) {
            //
        }

        try {
            Schema::table('auction_bids', function (Blueprint $table) {
                $table->index(['auction_id', 'is_winning'], 'idx_auction_winning');
            });
        } catch (\Throwable) {
            //
        }
    }
};
