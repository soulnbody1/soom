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
        DB::table('ads')->whereNull('public_id')->select('id')->orderBy('id')->chunkById(500, function ($ads): void {
            foreach ($ads as $ad) {
                DB::table('ads')->where('id', $ad->id)->whereNull('public_id')->update([
                    'public_id' => (string) Str::ulid(),
                ]);
            }
        });

        if (DB::table('ads')->whereNull('public_id')->exists()) {
            throw new \RuntimeException('Cannot require ads.public_id while null values exist.');
        }

        Schema::table('ads', function (Blueprint $table): void {
            $table->ulid('public_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('ads', function (Blueprint $table): void {
            $table->ulid('public_id')->nullable()->change();
        });
    }
};
