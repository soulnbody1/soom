<?php

declare(strict_types=1);

use App\Domain\ContentReview\Exceptions\ContentReviewException;

function contentReviewFiles(array $relativePaths): array
{
    $files = [];

    foreach ($relativePaths as $relative) {
        $path = app_path($relative);

        if (! is_dir($path)) {
            continue;
        }

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
    }

    return $files;
}

test('the content review subsystem never references the auction domain', function () {
    $violations = [];

    $forbidden = [
        'App\\Models\\Auction',
        'App\\Services\\Auction',
        'App\\Domain\\Auction',
        'App\\Repositories\\Auction',
        'App\\Policies\\Auction',
        'App\\DTO\\Auction',
        'App\\Jobs\\Auction',
        'App\\Http\\Controllers\\Auction',
        'App\\Http\\Requests\\Auction',
        'App\\Http\\Resources\\Auction',
        'Auction::',
        'AuctionStatus',
        'AuctionTransaction',
        'AuctionStateMachine',
        'auctions',
    ];

    foreach (contentReviewFiles([
        'Domain/ContentReview',
        'Services/ContentReview',
        'Models/ContentReview',
        'Repositories/ContentReview',
        'DTO/ContentReview',
        'Jobs/ContentReview',
        'Policies/ContentReview',
        'Http/Controllers/ContentReview',
        'Http/Requests/ContentReview',
        'Http/Resources/ContentReview',
        'Console/Commands/ContentReview',
    ]) as $file) {
        $contents = file_get_contents($file);

        foreach ($forbidden as $needle) {
            if (str_contains($contents, $needle)) {
                $violations[] = basename($file).' => '.$needle;
            }
        }
    }

    expect($violations)->toBe([]);
});

test('the decision engine performs no input or output', function () {
    $path = app_path('Services/ContentReview/Support/ContentReviewDecisionEngine.php');

    if (! is_file($path)) {
        expect(true)->toBeTrue();

        return;
    }

    $contents = file_get_contents($path);

    foreach (['DB::', 'Cache::', 'Http::', 'Log::', 'Storage::', 'Carbon::now(', 'now()', 'config('] as $forbidden) {
        expect($contents)->not->toContain($forbidden);
    }
});

test('only the apply action opens a transaction inside the content review subsystem', function () {
    $violations = [];
    $allowed = 'ApplyContentReviewDecisionAction.php';

    foreach (contentReviewFiles(['Services/ContentReview', 'Jobs/ContentReview', 'Repositories/ContentReview']) as $file) {
        if (str_ends_with($file, $allowed)) {
            continue;
        }

        $contents = file_get_contents($file);

        if (str_contains($contents, 'DB::transaction(')
            || str_contains($contents, 'DB::beginTransaction')
            || str_contains($contents, '->transaction->run')) {
            $violations[] = $file;
        }
    }

    expect($violations)->toBe([]);
});

test('providers never touch persistence', function () {
    $path = app_path('Services/ContentReview/Providers');

    if (! is_dir($path)) {
        expect(true)->toBeTrue();

        return;
    }

    $violations = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path)) as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $contents = file_get_contents($file->getPathname());

        foreach (['App\\Models', 'App\\Repositories', 'DB::'] as $forbidden) {
            if (str_contains($contents, $forbidden)) {
                $violations[] = $file->getPathname().':'.$forbidden;
            }
        }
    }

    expect($violations)->toBe([]);
});

function contentReviewCodeWithoutComments(string $file): string
{
    $code = '';

    foreach (token_get_all(file_get_contents($file)) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $code .= is_array($token) ? $token[1] : $token;
    }

    return $code;
}

test('vendor names never leak outside the provider layer', function () {
    $vendors = ['anthropic', 'openrouter', 'claude', 'openai', 'gemini'];
    $violations = [];

    foreach (contentReviewFiles([
        'Domain/ContentReview',
        'Services/ContentReview',
        'Models/ContentReview',
        'Repositories/ContentReview',
        'DTO/ContentReview',
        'Jobs/ContentReview',
        'Http/Controllers/ContentReview',
        'Http/Requests/ContentReview',
        'Http/Resources/ContentReview',
    ]) as $file) {
        if (str_contains(str_replace(DIRECTORY_SEPARATOR, '/', $file), '/Services/ContentReview/Providers/')) {
            continue;
        }

        $contents = strtolower(contentReviewCodeWithoutComments($file));

        foreach ($vendors as $vendor) {
            if (str_contains($contents, $vendor)) {
                $violations[] = basename($file).':'.$vendor;
            }
        }
    }

    expect($violations)->toBe([]);
});

test('the content review subsystem uses no floating point types', function () {
    $violations = [];

    foreach (contentReviewFiles([
        'Domain/ContentReview',
        'Services/ContentReview',
        'Models/ContentReview',
        'Repositories/ContentReview',
        'DTO/ContentReview',
        'Jobs/ContentReview',
    ]) as $file) {
        if (preg_match('/\b(float|double)\b|\(float\)|\(double\)/', file_get_contents($file))) {
            $violations[] = $file;
        }
    }

    expect($violations)->toBe([]);
});

test('content review domain errors are thrown through the static factory', function () {
    $violations = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())) as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        if (preg_match('/new ContentReviewException\(/', file_get_contents($file->getPathname()))) {
            $violations[] = $file->getPathname();
        }
    }

    expect($violations)->toBe([]);
});

test('the auction to content review bridge lives in the auction domain', function () {
    $bridge = app_path('Services/Auction/ContentReview/AuctionReviewSubjectAdapter.php');

    if (! is_file($bridge)) {
        expect(true)->toBeTrue();

        return;
    }

    $contents = file_get_contents($bridge);

    expect($contents)->toContain('ReviewSubjectAdapter')
        ->and($contents)->not->toMatch('/\b(float|double)\b/');
});

test('the content review exception carries a stable error code', function () {
    $exception = ContentReviewException::domain('policy_missing');

    expect($exception->getErrorCode())->toBe('policy_missing')
        ->and($exception->getStatusCode())->toBe(422);
});
