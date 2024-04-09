<?php

use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\Bootstrappers\MailConfigBootstrapper;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Listeners\RevertToCentralContext;

beforeEach(function () {
    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
    Event::listen(TenancyEnded::class, RevertToCentralContext::class);
});

test('MailTenancyBootstrapper maps tenant mail credentials to config as specified in the $credentialsMap property and makes the mailer use tenant credentials', function() {
    MailConfigBootstrapper::$credentialsMap = [
        'mail.mailers.smtp.username' => 'smtp_username',
        'mail.mailers.smtp.password' => 'smtp_password'
    ];

    config([
        'mail.default' => 'smtp',
        'mail.mailers.smtp.username' => $defaultUsername = 'default username',
        'mail.mailers.smtp.password' => 'no password',
        'tenancy.bootstrappers' => [MailConfigBootstrapper::class],
    ]);

    $tenant = Tenant::create(['smtp_password' => $password = 'testing password']);

    tenancy()->initialize($tenant);

    expect(array_key_exists('smtp_password', tenant()->getAttributes()))->toBeTrue();
    expect(array_key_exists('smtp_host', tenant()->getAttributes()))->toBeFalse();
    expect(config('mail.mailers.smtp.username'))->toBe($defaultUsername);
    expect(config('mail.mailers.smtp.password'))->toBe(tenant()->smtp_password);

    // Assert that the current mailer uses tenant's smtp_password
    assertMailerTransportUsesPassword($password);
});

test('MailTenancyBootstrapper reverts the config and mailer credentials to default when tenancy ends', function() {
    MailConfigBootstrapper::$credentialsMap = ['mail.mailers.smtp.password' => 'smtp_password'];
    config([
        'mail.default' => 'smtp',
        'mail.mailers.smtp.password' => $defaultPassword = 'no password',
        'tenancy.bootstrappers' => [MailConfigBootstrapper::class],
    ]);

    tenancy()->initialize(Tenant::create(['smtp_password' => $tenantPassword = 'testing password']));

    expect(config('mail.mailers.smtp.password'))->toBe($tenantPassword);

    assertMailerTransportUsesPassword($tenantPassword);

    tenancy()->end();

    expect(config('mail.mailers.smtp.password'))->toBe($defaultPassword);

    // Assert that the current mailer uses the default SMTP password
    assertMailerTransportUsesPassword($defaultPassword);
});

