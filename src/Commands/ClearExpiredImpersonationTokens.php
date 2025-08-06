<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Commands;

use Illuminate\Console\Command;
use Stancl\Tenancy\Features\UserImpersonation;

/**
 * This command clears expired impersonation tokens.
 * By default, all tokens older than UserImpersonation::$ttl (60 seconds by default)
 * are deleted. To override this, you can use the --ttl option, for example
 * --ttl=120, all tokens older than 120 seconds will be deleted, ignoring the default.
 *
 * @see Stancl\Tenancy\Features\UserImpersonation
 */
class ClearExpiredImpersonationTokens extends Command
{
    protected $signature = 'tenants:clear-expired-impersonation-tokens
                            {--ttl= : TTL in seconds for impersonation tokens (default is UserImpersonation::$ttl)}';

    protected $description = 'Clear expired impersonation tokens.';

    public function handle(): int
    {
        $this->components->info('Deleting expired impersonation tokens.');

        $ttl = (int) $this->option('ttl') ?: UserImpersonation::$ttl;
        $expirationDate = now()->subSeconds($ttl);

        $impersonationTokenModel = UserImpersonation::modelClass();

        $deletedTokenCount = $impersonationTokenModel::where('created_at', '<', $expirationDate)
            ->delete();

        $this->components->info($deletedTokenCount . ' expired impersonation ' . str('token')->plural($deletedTokenCount) . ' deleted.');

        return 0;
    }
}
