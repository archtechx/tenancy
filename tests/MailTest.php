<?php

declare(strict_types=1);

use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\TenancyMailManager;
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

test('tenancy swaps the MailManager singleton for an instance of TenancyMailManager', function() {
    tenancy()->initialize(Tenant::create());

    expect(app(MailManager::class))->toBeInstanceOf(TenancyMailManager::class);
});

test('SMTP mailer transport uses the correct tenant credentials', function() {
    TenancyMailManager::$tenantMailers = ['smtp'];
    MailTenancyBootstrapper::$credentialsMap = ['mail.mailers.smtp.password' => 'smtp_password'];

    $tenant = Tenant::create();

    // Initialize tenancy as $tenant and assert that the smtp mailer's transport has the correct password
    $assertTransportUsesPassword = function(string|null $password) use ($tenant) {
        tenancy()->initialize($tenant);

        $manager = app(MailManager::class);

        $getMailerViaManager = new ReflectionMethod($manager::class, 'get');
        $getMailerViaManager->setAccessible(true);
        $mailer = $getMailerViaManager->invoke($manager, 'smtp');

        $transportReflection = new ReflectionClass($transport = $mailer->getSymfonyTransport());
        $transportPassword = $transportReflection->getProperty('password');
        $transportPassword->setAccessible(true);
        $mailerPassword = $transportPassword->getValue($transport);

        expect($mailerPassword)->toBe((string) $password);

        tenancy()->end();
    };

    $assertTransportUsesPassword(null); // $tenant->smtp_password is null

    $tenant->update(['smtp_password' => $newPassword = 'changed']);

    $assertTransportUsesPassword($newPassword);

    $tenant->update(['smtp_password' => $newPassword = 'updated']);

    $assertTransportUsesPassword($newPassword);
});
