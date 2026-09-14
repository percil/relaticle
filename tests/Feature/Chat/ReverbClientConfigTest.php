<?php

declare(strict_types=1);

use App\Filament\Pages\Dashboard;
use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\Chat\ChatServiceProvider;

mutates(ChatServiceProvider::class);

beforeEach(function (): void {
    config()->set('reverb.apps.apps.0.key', 'pinned-reverb-key');
    config()->set('reverb.apps.apps.0.secret', 'pinned-reverb-secret');
    config()->set('reverb.apps.apps.0.options.host', 'pinned-reverb-host');
    config()->set('reverb.apps.apps.0.options.port', 18080);
    config()->set('reverb.apps.apps.0.options.scheme', 'https');

    $this->user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($this->user);
    $this->workspace = $this->user->currentWorkspace;
    Filament::setTenant($this->workspace);

    $this->html = $this->get(Dashboard::getUrl(tenant: $this->workspace))->getContent();
});

it('carries the configured Reverb app key in a reverb-app-key meta tag', function (): void {
    expect($this->html)->toContain('name="reverb-app-key" content="pinned-reverb-key"');
});

it('carries the configured host, port and scheme in their own meta tags', function (): void {
    expect($this->html)->toContain('name="reverb-host" content="pinned-reverb-host"')
        ->and($this->html)->toContain('name="reverb-port" content="18080"')
        ->and($this->html)->toContain('name="reverb-scheme" content="https"');
});

it('never renders the Reverb app secret into the browser payload', function (): void {
    expect($this->html)->not->toContain('pinned-reverb-secret');
});

it('emits the credential meta tag before the echo.js asset reference, from the same hook', function (): void {
    $metaPosition = strpos($this->html, 'name="reverb-app-key"');
    $echoAssetPosition = strpos($this->html, 'echo.js');

    expect($metaPosition)->not->toBeFalse()
        ->and($echoAssetPosition)->not->toBeFalse()
        ->and($metaPosition)->toBeLessThan($echoAssetPosition);
});
