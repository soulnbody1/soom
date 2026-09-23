<?php

declare(strict_types=1);

namespace App\Services\Ad\Actions;

use App\DTO\Ad\AdWriteInputDTO;
use App\Jobs\SendAdNotification;
use App\Models\Ad;
use App\Services\Ad\Support\AdAttributeWriter;
use App\Services\Ad\Support\AdCacheVersion;
use App\Services\Ad\Support\AdMediaService;
use App\Support\Market\MarketContext;
use Illuminate\Support\Facades\DB;
use Throwable;

final class CreateAdAction
{
    public function __construct(
        private readonly AdMediaService $media,
        private readonly AdAttributeWriter $attributes,
        private readonly AdCacheVersion $cacheVersion,
        private readonly MarketContext $market,
    ) {}

    public function execute(AdWriteInputDTO $input, int $ownerId): Ad
    {
        $uploadedPaths = $this->media->upload($input->images);

        try {
            $ad = DB::transaction(function () use ($input, $ownerId, $uploadedPaths): Ad {
                $ad = Ad::create([
                    ...$input->fields,
                    'country_id' => $this->market->market()->country_id,
                    'currency_code' => $this->market->market()->currency_code,
                    'user_id' => $ownerId,
                ]);

                $this->attributes->insert($ad, $input->attributes);
                $this->media->attach($ad, $uploadedPaths);

                return $ad;
            });
        } catch (Throwable $exception) {
            $this->media->discard($uploadedPaths);

            throw $exception;
        }

        $this->media->queueReel($ad, $input->reelVideo);
        SendAdNotification::dispatch((int) $ad->id, (int) $ad->market_id);
        $this->cacheVersion->bump();

        return $ad;
    }
}
