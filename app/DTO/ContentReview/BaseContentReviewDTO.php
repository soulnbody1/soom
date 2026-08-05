<?php

declare(strict_types=1);

namespace App\DTO\ContentReview;

use JsonSerializable;

abstract readonly class BaseContentReviewDTO implements JsonSerializable
{
    abstract public function toArray(): array;

    final public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
