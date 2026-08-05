<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            AuctionConfigurationSeeder::class,
            AuctionSeeder::class,
            ContentReviewSeeder::class,
        ]);

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'test@example.com',
            'phone' => '1234567890',
        ]);
    }
}
