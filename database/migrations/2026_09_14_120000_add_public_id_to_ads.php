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
        Schema::table('ads', function (Blueprint $table): void {
            $table->ulid('public_id')->nullable()->after('id');
            $table->unique('public_id', 'uq_ads_public_id');
        });

        DB::table('ads')->select('id')->orderBy('id')->chunkById(500, function ($ads): void {
            foreach ($ads as $ad) {
                DB::table('ads')->where('id', $ad->id)->update(['public_id' => (string) Str::ulid()]);
            }
        });

        $this->backfillNotificationReferences();
        $this->backfillSupportReferences();
        $this->backfillPromotionalLinks('banners');
        $this->backfillPromotionalLinks('charity_systems');

    }

    public function down(): void
    {
        Schema::table('ads', function (Blueprint $table): void {
            $table->dropUnique('uq_ads_public_id');
            $table->dropColumn('public_id');
        });
    }

    private function backfillNotificationReferences(): void
    {
        if (! Schema::hasTable('notifications')) {
            return;
        }

        DB::table('notifications')->orderBy('created_at')->chunk(500, function ($notifications): void {
            foreach ($notifications as $notification) {
                $data = json_decode((string) $notification->data, true);
                $adId = is_array($data) ? ($data['ad_id'] ?? null) : null;

                if (! is_numeric($adId)) {
                    continue;
                }

                $publicId = DB::table('ads')->where('id', (int) $adId)->value('public_id');

                $data['ad_id'] = $publicId;
                DB::table('notifications')->where('id', $notification->id)->update([
                    'data' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);
            }
        });
    }

    private function backfillSupportReferences(): void
    {
        if (! Schema::hasTable('support_tickets')) {
            return;
        }

        DB::table('support_tickets')->where('context_type', 'ad')->whereNotNull('context_id')
            ->orderBy('id')->chunkById(500, function ($tickets): void {
                foreach ($tickets as $ticket) {
                    if (! is_numeric($ticket->context_id)) {
                        continue;
                    }

                    $publicId = DB::table('ads')->where('id', (int) $ticket->context_id)->value('public_id');

                    DB::table('support_tickets')->where('id', $ticket->id)->update(['context_id' => $publicId]);
                }
            });
    }

    private function backfillPromotionalLinks(string $table): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'link_url')) {
            return;
        }

        DB::table($table)->where('link_url', 'like', '%/ads/%')->orderBy('id')->chunkById(500, function ($rows) use ($table): void {
            foreach ($rows as $row) {
                $unresolved = false;
                $link = preg_replace_callback('/\/ads\/(\d+)(?=$|[\/?#])/', function (array $matches) use (&$unresolved): string {
                    $publicId = DB::table('ads')->where('id', (int) $matches[1])->value('public_id');

                    if ($publicId === null) {
                        $unresolved = true;

                        return $matches[0];
                    }

                    return '/ads/'.$publicId;
                }, (string) $row->link_url);

                if ($unresolved || $link !== $row->link_url) {
                    DB::table($table)->where('id', $row->id)->update([
                        'link_url' => $unresolved ? null : $link,
                    ]);
                }
            }
        });
    }
};
