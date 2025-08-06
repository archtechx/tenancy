<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Commands;

use Illuminate\Console\Command;
use Stancl\Tenancy\Features\UserImpersonation;

/**
 * @see Stancl\Tenancy\Features\UserImpersonation
 */
class ClearExpiredImpersonationTokens extends Command
{
    protected $signature = 'tenants:clear-expired-impersonation-tokens
                            {--ttl= : TTL in seconds for impersonation tokens (default is UserImpersonation::$ttl)}';

    protected $description = 'Remove expired impersonation tokens.';

    public function handle(): int
    {
        $this->components->info('Removing expired impersonation tokens.');

        $ttl = (int) $this->option('ttl') ?: UserImpersonation::$ttl;
        $expirationDate = now()->subSeconds($ttl);

        $impersonationTokenModel = UserImpersonation::modelClass();

        $deletedTokenCount = $impersonationTokenModel::where('created_at', '<', $expirationDate)
            ->delete();

        $this->components->info($deletedTokenCount . ' expired impersonation ' . str('token')->plural($deletedTokenCount) . ' deleted.');

        return 0;
    }
}
