<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Auction\AuctionConfigurationVersion;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

final class AuctionConfigurationSeeder extends Seeder
{
    public function run(): void
    {
        AuctionConfigurationVersion::firstOrCreate(
            ['version_number' => 1],
            [
                'is_active' => true,
                'published_at' => Carbon::now()->subDay(),
                'configuration' => [
                    'seller_deposit_minor' => 10000,          // e.g. 10.000 JOD / 100.00 EGP/USD
                    'bidder_deposit_minor' => 5000,           // e.g. 5.000 JOD / 50.00 EGP/USD
                    'platform_fee_type' => 'percentage',
                    'platform_fee_basis_points' => 250,       // 2.5%
                    'platform_fee_fixed_minor' => 0,
                    'minimum_bid_increment_minor' => 1000,    // e.g. 1.000 JOD / 10.00 EGP/USD
                    'extension_window_seconds' => 300,        // 5 minutes
                    'extension_duration_seconds' => 600,      // 10 minutes
                    'maximum_extension_count' => 6,
                    'winner_payment_deadline_hours' => 48,
                    'handover_deadline_hours' => 72,
                    'non_winner_deposit_policy' => 'hold_all_eligible_bidders_until_winner_payment',
                    'non_winner_deposit_hold_count' => 1,
                    'alternative_winner_enabled' => true,
                    'winner_default_deposit_policy' => [
                        'disposition' => 'full_forfeit',
                        'forfeit_amount_minor' => 0,
                    ],
                    'seller_deposit_policy' => [
                        'unsold' => 'refund',
                        'completed' => 'refund',
                        'seller_cancellation_before_start' => 'refund',
                        'seller_cancellation_after_start' => 'manual_review',
                        'admin_cancellation_platform_fault' => 'refund',
                        'admin_cancellation_seller_fault' => 'forfeit',
                        'admin_cancellation_neutral' => 'refund',
                        'admin_cancellation_fraud_or_compliance' => 'manual_review',
                        'system_cancellation_platform_fault' => 'refund',
                        'system_cancellation_seller_fault' => 'forfeit',
                        'system_cancellation_neutral' => 'refund',
                        'winner_default' => 'keep_held',
                        'seller_breach' => 'forfeit',
                        'dispute_complete' => 'refund',
                        'dispute_cancel' => 'manual_review',
                        'dispute_resume_handover' => 'keep_held',
                    ],
                ],
            ]
        );
    }
}
