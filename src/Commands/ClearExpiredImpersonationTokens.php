<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Commands;

use Illuminate\Console\Command;
use Stancl\Tenancy\Features\UserImpersonation;

/**
 * This command clears impersonation tokens.
 * By default, only expired tokens (= tokens older than 60s, which is the UserImpersonation::$ttl default) are deleted.
 *
 * To override the default behavior, e.g. to clear all tokens newer than 60s,
 * you can pass the seconds in the --ttl option.
 *
 * For example, `tenants:clear-expired-impersonation-tokens --ttl=30` will clear all tokens older than 30 seconds, ignoring the default.
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
