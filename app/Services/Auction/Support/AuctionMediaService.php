<?php

declare(strict_types=1);

namespace App\Services\Auction\Support;

use App\Models\Auction\Auction;
use App\Models\Auction\AuctionMedia;
use Illuminate\Http\UploadedFile;

/**
 * Handles media file storage for auctions.
 * Encapsulates Storage/UploadedFile operations away from Actions/Controllers.
 */
final class AuctionMediaService
{
    /**
     * Store uploaded media files for an auction.
     *
     * @param  array<int, UploadedFile>  $media
     */
    public function storeAuctionMedia(Auction $auction, array $media): void
    {
        foreach ($media as $index => $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $path = $file->store("auctions/{$auction->public_id}", 'spaces');

            AuctionMedia::create([
                'auction_id' => $auction->id,
                'disk' => 'spaces',
                'path' => $path,
                'mime_type' => (string) $file->getMimeType(),
                'size_bytes' => (int) $file->getSize(),
                'sort_order' => $index,
                'is_primary' => $index === 0,
            ]);
        }
    }
}
