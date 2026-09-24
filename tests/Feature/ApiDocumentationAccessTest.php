<?php

use App\Http\Middleware\ProtectApiDocumentation;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

beforeEach(function (): void {
    config()->set('app.url', 'https://docs.example.test');
    config()->set('scramble.auth.username', 'docs-user');
    config()->set('scramble.auth.password', 'correct-password');
});

it('challenges unauthenticated documentation requests', function (): void {
    $response = app(ProtectApiDocumentation::class)->handle(
        Request::create('https://docs.example.test/docs/api'),
        fn () => response('docs')
    );

    expect($response->getStatusCode())->toBe(401)
        ->and($response->headers->get('WWW-Authenticate'))
        ->toBe('Basic realm="Soom API Documentation", charset="UTF-8"');
});

it('allows the configured documentation credentials', function (): void {
    $request = Request::create(
        'https://docs.example.test/docs/api',
        'GET',
        server: [
            'PHP_AUTH_USER' => 'docs-user',
            'PHP_AUTH_PW' => 'correct-password',
        ]
    );

    $response = app(ProtectApiDocumentation::class)->handle(
        $request,
        fn () => response('docs')
    );

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getContent())->toBe('docs');
});

it('does not expose documentation on another host', function (): void {
    $request = Request::create(
        'https://api-docs.example.test/docs/api',
        'GET',
        server: [
            'PHP_AUTH_USER' => 'docs-user',
            'PHP_AUTH_PW' => 'correct-password',
        ]
    );

    app(ProtectApiDocumentation::class)->handle($request, fn () => response('docs'));
})->throws(NotFoundHttpException::class);

it('fails closed when documentation credentials are missing', function (): void {
    config()->set('scramble.auth.password', null);

    app(ProtectApiDocumentation::class)->handle(
        Request::create('https://docs.example.test/docs/api'),
        fn () => response('docs')
    );
})->throws(NotFoundHttpException::class);

it('targets market and admin api hosts without forwarding ambient credentials', function (): void {
    expect(config('scramble.servers'))->toMatchArray([
        'JO Market API' => 'https://api-jo.soom.test/api',
        'EG Market API' => 'https://api-eg.soom.test/api',
        'Admin API' => 'https://api-admin.soom.test/api',
    ])->and(config('scramble.renderers.elements.tryItCredentialsPolicy'))->toBe('omit')
        ->and(config('scramble.renderers.scalar.credentials'))->toBe('omit');
});
