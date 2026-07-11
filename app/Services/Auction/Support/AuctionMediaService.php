<?php

declare(strict_types=1);

namespace App\Services\Auction\Support;

use App\Models\Auction\Auction;
use App\Models\Auction\AuctionMedia;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class AuctionMediaService
{
    /**
     * @param  array<int, UploadedFile>  $media
     * @return array<int, array{disk: string, path: string}>
     */
    public function storeAuctionMedia(Auction $auction, array $media): array
    {
        $stored = [];

        try {
            foreach ($media as $index => $file) {
                if (! $file instanceof UploadedFile) {
                    continue;
                }

                $path = $file->store("auctions/{$auction->public_id}", 'spaces');
                $stored[] = ['disk' => 'spaces', 'path' => $path];

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

            return $stored;
        } catch (Throwable $exception) {
            $this->deleteStoredMedia($stored);

            throw $exception;
        }
    }

    /**
     * @param  array<int, array{disk: string, path: string}>  $stored
     */
    public function deleteStoredMedia(array $stored): void
    {
        foreach ($stored as $file) {
            Storage::disk($file['disk'])->delete($file['path']);
        }
    }
}
