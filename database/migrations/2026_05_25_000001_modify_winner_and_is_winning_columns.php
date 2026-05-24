<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Modify auctions table: change winner_id from foreignId to unsignedBigInteger without foreign key
        Schema::table('auctions', function (Blueprint $table) {
            // Drop the foreign key constraint first
            $table->dropForeign(['winner_id']);
            
            // Change column type from foreignId (which is unsignedBigInteger) to unsignedBigInteger without foreign key
            // We'll use change() to modify the column, but we need to remove the foreign key first
            $table->unsignedBigInteger('winner_id')->nullable()->change();
        });

        // Modify auction_bids table: change is_winning from boolean to unsignedBigInteger foreign key to users
        Schema::table('auction_bids', function (Blueprint $table) {
            // Drop the existing boolean column
            $table->dropColumn('is_winning');
            
            // Add new column as unsignedBigInteger foreign key to users
            $table->unsignedBigInteger('is_winning')->nullable();
            $table->foreign('is_winning')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        // Reverse the changes

        // Modify auction_bids table: revert is_winning back to boolean
        Schema::table('auction_bids', function (Blueprint $table) {
            $table->dropForeign(['is_winning']);
            $table->dropColumn('is_winning');
            $table->boolean('is_winning')->default(false);
        });

        // Modify auctions table: revert winner_id back to foreignId with constraint
        Schema::table('auctions', function (Blueprint $table) {
            $table->dropForeign(['winner_id']); // already dropped in up, but ensure
            $table->foreignId('winner_id')->nullable()->constrained()->change();
        });
    }
};