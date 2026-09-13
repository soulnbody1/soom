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
        Schema::create('seller_ratings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('reviewer_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('message');
            $table->timestamps();

            $table->unique(['seller_id', 'reviewer_id']);
            $table->index(['seller_id', 'created_at']);
        });

        $this->addCheckConstraints();
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_ratings');
    }

    private function addCheckConstraints(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'pgsql'], true)) {
            return;
        }

        DB::statement('ALTER TABLE seller_ratings ADD CONSTRAINT chk_seller_rating_score CHECK (rating BETWEEN 1 AND 5)');
        DB::statement('ALTER TABLE seller_ratings ADD CONSTRAINT chk_seller_rating_distinct_users CHECK (seller_id <> reviewer_id)');
    }
};
