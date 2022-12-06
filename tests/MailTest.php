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

test('SMTP mailer transport uses the correct tenant credentials', function() {
    MailTenancyBootstrapper::$credentialsMap = ['mail.mailers.smtp.password' => 'smtp_password'];

    $tenant = Tenant::create();

    tenancy()->initialize($tenant);

    assertMailerTransportUsesPassword(null); // $tenant->smtp_password is null

    tenancy()->end($tenant);

    $tenant->update(['smtp_password' => $newPassword = 'changed']);

    tenancy()->initialize($tenant);

    assertMailerTransportUsesPassword($newPassword);

    tenancy()->end($tenant);

    $tenant->update(['smtp_password' => $newPassword = 'updated']);
    tenancy()->initialize($tenant);

    assertMailerTransportUsesPassword($newPassword);
})->group('mailer');
