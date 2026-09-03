<?php

declare(strict_types=1);

use App\Providers\HealthServiceProvider;
use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Relaticle\Chat\Settings\ChatSettings;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Checks\DatabaseCheck;
use Spatie\Health\Checks\Checks\HorizonCheck;
use Spatie\Health\Checks\Checks\RedisCheck;
use Spatie\Health\Facades\Health;

mutates(HealthServiceProvider::class);

it('does not register health checks when disabled', function () {
    Health::clearChecks();

    config()->set('app.health_checks_enabled', false);

    $provider = new HealthServiceProvider(app());
    $provider->boot();

    expect(Health::registeredChecks())->toBeEmpty();
});

it('registers health checks when enabled', function () {
    Health::clearChecks();

    config()->set('app.health_checks_enabled', true);

    $provider = new HealthServiceProvider(app());
    $provider->boot();

    $checkClasses = collect(Health::registeredChecks())
        ->map(fn ($check) => $check::class);

    expect($checkClasses)
        ->toContain(DatabaseCheck::class)
        ->toContain(RedisCheck::class)
        ->toContain(HorizonCheck::class);
});

it('registers a chat provider check for a model that only the runtime settings overlay makes servable', function (): void {
    // Guards the boot-order invariant: a chat provider check derived from the
    // runtime catalog must survive provider boot order. Asserting this against a
    // hand-constructed HealthServiceProvider, as the two cases above do, cannot
    // catch the regression, which is why this case pays for a full application
    // bootstrap through the real bootstrap/app.php entry point.
    $originalApp = $this->app;
    $anthropicEffort = (string) config('chat.anthropic_effort');

    $overlaidModels = [[
        'label' => 'GPT OSS 20B',
        'provider' => 'ollama_cloud',
        'model' => 'gpt-oss:20b',
        'min_plan' => 'free',
        'credit_multiplier' => 1.0,
        'input_per_mtok' => null,
        'output_per_mtok' => null,
        'auto' => false,
        'enabled' => true,
        'capabilities' => [
            'supports_tools' => true,
            'write_guard' => 'prompt',
        ],
        'verified_at' => '2026-09-03T00:00:00+00:00',
    ]];

    /** @var Application $app */
    $app = require base_path('bootstrap/app.php');

    $app->booting(function (Application $app) use ($anthropicEffort, $overlaidModels): void {
        $app['config']->set('app.health_checks_enabled', true);
        $app['config']->set('ai.providers.ollama_cloud.key', 'ollama-cloud-test-key');

        ChatSettings::fake([
            'models' => $overlaidModels,
            'anthropic_effort' => $anthropicEffort,
        ]);
    });

    try {
        $app->make(Kernel::class)->bootstrap();

        $checkNames = collect($app->make(Spatie\Health\Health::class)->registeredChecks())
            ->map(fn (Check $check): string => $check->getName());

        expect($checkNames)->toContain('Chat provider: ollama_cloud');
        expect($app['config']->get('chat.models'))->toEqual($overlaidModels);
    } finally {
        Container::setInstance($originalApp);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($originalApp);
        Model::setConnectionResolver($originalApp->make(ConnectionResolverInterface::class));
        Model::setEventDispatcher($originalApp->make(Dispatcher::class));
        $app->flush();
    }
});
