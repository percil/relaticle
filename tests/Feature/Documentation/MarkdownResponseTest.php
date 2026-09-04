<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Spatie\MarkdownResponse\Middleware\ProvideMarkdownResponse;

it('serves docs pages as clean article markdown without site chrome', function (): void {
    $markdown = $this->get('/developers/self-hosting', ['Accept' => 'text/markdown'])
        ->assertOk()
        ->getContent();

    expect($markdown)->toContain('Quick Start')
        ->and($markdown)->toContain('Reverse Proxy and SSL')
        ->and($markdown)->not->toContain('Sign In')
        ->and($markdown)->not->toContain('[ Start for free ]')
        ->and($markdown)->not->toContain('Skip to content')
        ->and($markdown)->not->toContain('Searching help and developer docs')
        ->and($markdown)->not->toContain('On this page');
});

it('declares Vary: Accept on both variants of a content-negotiated route', function (): void {
    $htmlVary = $this->get('/developers/self-hosting')
        ->assertOk()
        ->headers->get('Vary', '');

    $markdownVary = $this->get('/developers/self-hosting', ['Accept' => 'text/markdown'])
        ->assertOk()
        ->headers->get('Vary', '');

    expect($htmlVary)->toContain('Accept')
        ->and($markdownVary)->toContain('Accept');
});

it('converts a real html table to pipe-table markdown', function (): void {
    Route::middleware(ProvideMarkdownResponse::class)->get('/__markdown-table-fixture', fn () => response(<<<'HTML'
        <html>
        <body>
        <main>
            <table>
                <thead>
                    <tr><th>Plan</th><th>Price</th></tr>
                </thead>
                <tbody>
                    <tr><td>Free</td><td>$0</td></tr>
                    <tr><td>Pro</td><td>$29</td></tr>
                </tbody>
            </table>
        </main>
        </body>
        </html>
        HTML));

    $markdown = $this->get('/__markdown-table-fixture', ['Accept' => 'text/markdown'])
        ->assertOk()
        ->getContent();

    expect($markdown)->toContain('| Plan | Price |')
        ->and($markdown)->toContain('| Free | $0 |')
        ->and($markdown)->toContain('| Pro | $29 |');
});
