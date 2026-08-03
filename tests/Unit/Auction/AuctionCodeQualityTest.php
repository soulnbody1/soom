<?php

declare(strict_types=1);

test('auction financial code does not use floating point types or casts', function () {
    $paths = [
        app_path('Domain/Auction'),
        app_path('Services/Auction'),
        app_path('Models/Auction'),
        app_path('Http/Controllers/Auction'),
        app_path('Http/Requests/Auction'),
        app_path('Http/Resources/Auction'),
    ];

    $violations = [];

    foreach ($paths as $path) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path)) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            if (preg_match('/\b(float|double)\b|\(float\)|\(double\)/', $contents)) {
                $violations[] = $file->getPathname();
            }
        }
    }

    expect($violations)->toBe([]);
});

test('legacy auction table names are not referenced by application code', function () {
    $legacy = [
        'payment'.'_slips',
        'auction'.'_rules',
        'auctions'.'_configurations',
        'auction'.'_images',
        'is'.'_winning',
    ];
    $violations = [];

    foreach ([app_path(), base_path('routes'), base_path('tests')] as $path) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path)) as $file) {
            if (! $file->isFile() || ! in_array($file->getExtension(), ['php'], true) || $file->getPathname() === __FILE__) {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            foreach ($legacy as $needle) {
                if (str_contains($contents, $needle)) {
                    $violations[] = $file->getPathname().':'.$needle;
                }
            }
        }
    }

    expect($violations)->toBe([]);
});

test('auction domain errors are thrown through code carrying factories', function () {
    $violations = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())) as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        if (preg_match('/new AuctionException\(__\(/', file_get_contents($file->getPathname()))) {
            $violations[] = $file->getPathname();
        }
    }

    expect($violations)->toBe([]);
});

test('the user auction resource never emits conditional keys', function () {
    $contents = file_get_contents(app_path('Http/Resources/Auction/UserAuctionResource.php'));

    expect($contents)->not->toContain('whenLoaded(')
        ->and($contents)->not->toContain('mergeWhen(')
        ->and($contents)->not->toContain('$this->when(');
});

test('no auction action transitions an auction into the legacy disputed status', function () {
    $violations = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Services/Auction/Actions'))) as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $contents = file_get_contents($file->getPathname());
        if (preg_match('/transition\(\s*\$auction\s*,\s*AuctionStatus::Disputed/', $contents)) {
            $violations[] = $file->getPathname();
        }
    }

    expect($violations)->toBe([]);
});
