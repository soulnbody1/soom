<?php

declare(strict_types=1);

namespace App\Domain\ContentReview\Enums;

enum StructuredOutputStrategy: string
{
    case Tool = 'tool';
    case JsonSchema = 'json_schema';
    case JsonObject = 'json_object';
}
