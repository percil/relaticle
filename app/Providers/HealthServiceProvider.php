<?php

declare(strict_types=1);

namespace App\Providers;

use App\Health\ChatProviderCheck;
use Illuminate\Support\ServiceProvider;
use Spatie\CpuLoadHealthCheck\CpuLoadCheck;
use Spatie\Health\Checks\Checks\CacheCheck;
use Spatie\Health\Checks\Checks\DatabaseCheck;
use Spatie\Health\Checks\Checks\DatabaseConnectionCountCheck;
use Spatie\Health\Checks\Checks\DatabaseSizeCheck;
use Spatie\Health\Checks\Checks\DatabaseTableSizeCheck;
use Spatie\Health\Checks\Checks\DebugModeCheck;
use Spatie\Health\Checks\Checks\EnvironmentCheck;
use Spatie\Health\Checks\Checks\HorizonCheck;
use Spatie\Health\Checks\Checks\QueueCheck;
use Spatie\Health\Checks\Checks\RedisCheck;
use Spatie\Health\Checks\Checks\RedisMemoryUsageCheck;
use Spatie\Health\Checks\Checks\ScheduleCheck;
use Spatie\Health\Checks\Checks\UsedDiskSpaceCheck;
use Spatie\Health\Facades\Health;
use Spatie\SecurityAdvisoriesHealthCheck\SecurityAdvisoriesCheck;

final class HealthServiceProvider extends ServiceProvider
{
    /**
     * Chat provider checks derive from config('chat.models'). ChatServiceProvider
     * overlays the runtime-editable catalog onto that key from its own boot(), which
     * runs after this provider in bootstrap/providers.php. Building the check list
     * here, eagerly, would freeze it on the seed in packages/Chat/config/chat.php,
     * where a row whose capabilities are measured later by ModelProbe still reads
     * null and is dropped by CatalogEntry::isServable(). Ollama Cloud is the first
     * provider seeded that way, so its check silently never registered. Deferring
     * registration to $this->app->booted() runs it after every provider has booted,
     * which makes this independent of the order in bootstrap/providers.php.
     */
    public function boot(): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $this->app->booted(function (): void {
            Health::checks([
                DatabaseCheck::new(),

                DatabaseConnectionCountCheck::new()
                    ->warnWhenMoreConnectionsThan(60)
                    ->failWhenMoreConnectionsThan(80),

                DatabaseSizeCheck::new()
                    ->failWhenSizeAboveGb(errorThresholdGb: 10.0),

                DatabaseTableSizeCheck::new()
                    ->table('custom_field_values', maxSizeInMb: 5_000)
                    ->table('notes', maxSizeInMb: 5_000)
                    ->table('companies', maxSizeInMb: 2_000)
                    ->table('people', maxSizeInMb: 2_000)
                    ->table('opportunities', maxSizeInMb: 2_000)
                    ->table('tasks', maxSizeInMb: 2_000)
                    ->table('media', maxSizeInMb: 5_000)
                    ->table('jobs', maxSizeInMb: 1_000),

                RedisCheck::new(),

                RedisMemoryUsageCheck::new()
                    ->warnWhenAboveMb(500)
                    ->failWhenAboveMb(1_000),

                HorizonCheck::new(),

                QueueCheck::new()
                    ->name('Queue: default'),

                QueueCheck::new()
                    ->name('Queue: imports')
                    ->onQueue('imports'),

                UsedDiskSpaceCheck::new()
                    ->warnWhenUsedSpaceIsAbovePercentage(70)
                    ->failWhenUsedSpaceIsAbovePercentage(90),

                CpuLoadCheck::new()
                    ->failWhenLoadIsHigherInTheLast5Minutes(8.0)
                    ->failWhenLoadIsHigherInTheLast15Minutes(4.0),

                DebugModeCheck::new(),

                EnvironmentCheck::new(),

                ScheduleCheck::new()
                    ->heartbeatMaxAgeInMinutes(2),

                SecurityAdvisoriesCheck::new(),

                CacheCheck::new(),

                ...ChatProviderCheck::forConfiguredProviders(),
            ]);
        });
    }

    private function isEnabled(): bool
    {
        return (bool) config('app.health_checks_enabled', false);
    }
}
