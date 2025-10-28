<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Commands;

use Illuminate\Console\Command;
use Stancl\Tenancy\Features\UserImpersonation;

/**
 * Clears expired impersonation tokens.
 *
 * Tokens older than UserImpersonation::$ttl (60 seconds by default) are considered expired.
 *
 * @see Stancl\Tenancy\Features\UserImpersonation
 */
class ClearExpiredImpersonationTokens extends Command
{
    protected $signature = 'tenants:clear-expired-impersonation-tokens';

    protected $description = 'Clear expired impersonation tokens.';

    public function handle(): int
    {
        $this->components->info('Deleting expired impersonation tokens.');

        $expirationDate = now()->subSeconds(UserImpersonation::$ttl);

        $impersonationTokenModel = UserImpersonation::modelClass();

        $deletedTokenCount = $impersonationTokenModel::where('created_at', '<', $expirationDate)
            ->delete();

        $this->components->info($deletedTokenCount . ' expired impersonation ' . str('token')->plural($deletedTokenCount) . ' deleted.');

        return 0;
    }
}
