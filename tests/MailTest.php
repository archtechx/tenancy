<?php

declare(strict_types=1);

use Illuminate\Mail\MailManager;
use Stancl\Tenancy\Tests\Etc\Tenant;
use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Listeners\RevertToCentralContext;
use Stancl\Tenancy\Bootstrappers\MailConfigBootstrapper;
use function Stancl\Tenancy\Tests\pest;
use function Stancl\Tenancy\Tests\withTenantDatabases;

beforeEach(function() {
    config(['mail.default' => 'smtp']);
    config(['tenancy.bootstrappers' => [MailConfigBootstrapper::class]]);
    MailConfigBootstrapper::$credentialsMap = [];

    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
    Event::listen(TenancyEnded::class, RevertToCentralContext::class);
});

afterEach(function () {
    MailConfigBootstrapper::$credentialsMap = [];
});

// Initialize tenancy as $tenant and assert that the smtp mailer's transport has the correct password
function assertMailerTransportUsesPassword(string|null $password) {
    $manager = app(MailManager::class);
    $mailer = invade($manager)->get('smtp');
    $mailerPassword = invade($mailer->getSymfonyTransport())->password;

    expect($mailerPassword)->toBe((string) $password);
};

test('mailer transport uses the correct credentials', function() {
    withTenantDatabases();

    config(['mail.default' => 'smtp', 'mail.mailers.smtp.password' => $defaultPassword = 'DEFAULT']);
    MailConfigBootstrapper::$credentialsMap = ['mail.mailers.smtp.password' => 'smtp_password'];

    tenancy()->initialize($tenant = Tenant::create());
    assertMailerTransportUsesPassword($defaultPassword); // $tenant->smtp_password is not set, so the default password should be used
    tenancy()->end();

    // Assert mailer uses the updated password
    $tenant->update(['smtp_password' => $newPassword = 'changed']);

    tenancy()->initialize($tenant);
    assertMailerTransportUsesPassword($newPassword);
    tenancy()->end();

    // Assert mailer uses the correct password after switching to a different tenant
    tenancy()->initialize(Tenant::create(['smtp_password' => $newTenantPassword = 'updated']));
    assertMailerTransportUsesPassword($newTenantPassword);
    tenancy()->end();

    // Assert nested tenant properties can be mapped to mail config (e.g. using dot notation)
    // Add 'mail' JSON column to tenants table where smtp_password will be stored
    pest()->artisan('tenants:migrate', [
        '--path' => __DIR__ . '/Etc/mail_migrations',
        '--realpath' => true,
    ])->assertExitCode(0);

    MailConfigBootstrapper::$credentialsMap = ['mail.mailers.smtp.password' => 'mail.smtp_password'];
    tenancy()->initialize($tenant = Tenant::create(['mail' => ['smtp_password' => $nestedTenantPassword = 'nested']]));
    assertMailerTransportUsesPassword($nestedTenantPassword);
    tenancy()->end();

    // Assert mailer uses the default password after tenancy ends
    assertMailerTransportUsesPassword($defaultPassword);
});


test('initializing and ending tenancy binds a fresh MailManager instance without cached mailers', function() {
    withTenantDatabases();

    $mailers = fn() => invade(app(MailManager::class))->mailers;

    app(MailManager::class)->mailer('smtp');

    expect($mailers())->toHaveCount(1);

    tenancy()->initialize(Tenant::create());

    expect($mailers())->toHaveCount(0);

    app(MailManager::class)->mailer('smtp');

    expect($mailers())->toHaveCount(1);

    tenancy()->end();

    expect($mailers())->toHaveCount(0);
});
