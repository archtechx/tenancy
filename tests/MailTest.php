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
    expect(app(MailManager::class))->toBeInstanceOf(TenancyMailManager::class);
});

test('MailTenancyBootstrapper maps tenant mail credentials to config as specified in the credentialsMap property', function() {
    MailTenancyBootstrapper::$credentialsMap = [
        'mail.mailers.smtp.username' => 'smtp_username',
        'mail.mailers.smtp.password' => 'smtp_password'
    ];

    config([
        'mail.mailers.smtp.username' => $defaultUsername = 'default username',
        'mail.mailers.smtp.password' => 'no password'
    ]);

    Tenant::create(['smtp_password' => 'testing password'])->run(function() use ($defaultUsername) {
        expect(array_key_exists('smtp_password', tenant()->getAttributes()))->toBeTrue();
        expect(array_key_exists('smtp_host', tenant()->getAttributes()))->toBeFalse();
        expect(config('mail.mailers.smtp.username'))->toBe($defaultUsername);
        expect(config('mail.mailers.smtp.password'))->toBe(tenant()->smtp_password);
    });
});

test('MailTenancyBootstrapper reverts the config back to default when tenancy ends', function() {
    MailTenancyBootstrapper::$credentialsMap = ['mail.mailers.smtp.password' => 'smtp_password'];
    config(['mail.mailers.smtp.password' => $defaultPassword = 'no password']);

    Tenant::create(['smtp_password' => 'testing password'])->run(fn() => '');

    expect(config('mail.mailers.smtp.password'))->toBe($defaultPassword);
});

test('SMTP mailer transporter uses the correct tenant credentials', function() {
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
