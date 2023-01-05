<?php

declare(strict_types=1);

use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Listeners\RevertToCentralContext;
use Stancl\Tenancy\Bootstrappers\MailTenancyBootstrapper;

beforeEach(function() {
    config(['mail.default' => 'smtp']);

    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
    Event::listen(TenancyEnded::class, RevertToCentralContext::class);
});

// Initialize tenancy as $tenant and assert that the smtp mailer's transport has the correct password
function assertMailerTransportUsesPassword(string|null $password) {
    $manager = app(MailManager::class);
    $mailer = invade($manager)->get('smtp');
    $mailerPassword = invade($mailer->getSymfonyTransport())->password;

    expect($mailerPassword)->toBe((string) $password);
};

test('mailer transport uses the correct credentials', function() {
    config(['mail.default' => 'smtp', 'mail.mailers.smtp.password' => $defaultPassword = 'DEFAULT']);
    MailTenancyBootstrapper::$credentialsMap = ['mail.mailers.smtp.password' => 'smtp_password'];

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

    // Assert mailer uses the default password after tenancy ends
    assertMailerTransportUsesPassword($defaultPassword);
});


test('initializing and ending tenancy binds a fresh MailManager instance without cached mailers', function() {
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
