<?php

declare(strict_types=1);

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Mail;
use Stancl\Tenancy\Tests\Etc\Tenant;
use Illuminate\Support\Facades\Event;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Symfony\Component\Mailer\Transport\NullTransport;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Stancl\Tenancy\Bootstrappers\QueueTenancyBootstrapper;
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
    MailConfigBootstrapper::$mailer = null;
    PasswordRecordingMailable::$passwords = [];

    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
    Event::listen(TenancyEnded::class, RevertToCentralContext::class);
});

afterEach(function () {
    MailConfigBootstrapper::$credentialsMap = [];
    MailConfigBootstrapper::$mailer = null;
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


test('initializing and ending tenancy clears the cached mailers', function () {
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

test('mappings specified in credentialsMap override default mapPresets', function () {
    config(['mail.mailers.smtp.password' => 'DEFAULT']);

    // Custom mapping for a config key that's also mapped by the smtp preset (to smtp_password)
    MailConfigBootstrapper::$credentialsMap = ['mail.mailers.smtp.password' => 'custom_smtp_password'];

    tenancy()->initialize(Tenant::create([
        'smtp_host' => 'tenant.host',
        'smtp_password' => 'preset_value',
        'custom_smtp_password' => 'custom_value',
    ]));

    // Custom mapping is used
    expect(config('mail.mailers.smtp.password'))->toBe('custom_value');

    // Preset mappings that aren't overridden still apply
    expect(config('mail.mailers.smtp.host'))->toBe('tenant.host');

    // Initializing tenancy doesn't write the preset mappings into the public static property
    expect(MailConfigBootstrapper::$credentialsMap)->toBe(['mail.mailers.smtp.password' => 'custom_smtp_password']);
});

test('the Mail facade uses the current context credentials after tenancy is initialized or ended', function () {
    config(['mail.mailers.smtp.password' => 'central']);

    $tenant1 = Tenant::create(['smtp_password' => 'tenant1']);
    $tenant2 = Tenant::create(['smtp_password' => 'tenant2']);

    Mail::to('foo@example.com')->send(new PasswordRecordingMailable);

    tenancy()->initialize($tenant1);
    Mail::to('foo@example.com')->send(new PasswordRecordingMailable);

    tenancy()->initialize($tenant2);
    Mail::to('foo@example.com')->send(new PasswordRecordingMailable);

    tenancy()->end();
    Mail::to('foo@example.com')->send(new PasswordRecordingMailable);

    expect(PasswordRecordingMailable::$passwords)->toBe(['central', 'tenant1', 'tenant2', 'central']);
});

// With Mail::to() (facade use) we mainly worry about the facade instance cache. When using *notifications*
// there's also ChannelManager which indirectly holds the MailManager instance so here we test for a regression
// where the MailManager gets replaced rather than just having its mailers cleared
test('queued mail notifications use the credentials of the tenant they were dispatched in', function () {
    config([
        'queue.default' => 'redis',
        'mail.mailers.smtp.password' => 'central',
        'tenancy.bootstrappers' => [MailConfigBootstrapper::class, QueueTenancyBootstrapper::class],
    ]);
    QueueTenancyBootstrapper::__constructStatic(app());

    $tenant1 = Tenant::create(['smtp_password' => 'tenant1']);
    $tenant2 = Tenant::create(['smtp_password' => 'tenant2']);

    tenancy()->initialize($tenant1);
    NotificationFacade::route('mail', 'foo@example.com')->notify(new PasswordRecordingNotification);

    tenancy()->initialize($tenant2);
    NotificationFacade::route('mail', 'foo@example.com')->notify(new PasswordRecordingNotification);

    tenancy()->end();

    // Both jobs are processed by the same long-running worker
    pest()->artisan('queue:work --stop-when-empty');

    expect(PasswordRecordingMailable::$passwords)->toBe(['tenant1', 'tenant2']);
});

test('custom transports registered using Mail::extend work in both the tenant and the central context', function () {
    config(['mail.mailers.custom' => ['transport' => 'custom']]);

    Mail::extend('custom', fn () => new NullTransport);

    tenancy()->initialize(Tenant::create());
    expect(app(MailManager::class)->mailer('custom')->getSymfonyTransport())->toBeInstanceOf(NullTransport::class);
    tenancy()->end();

    expect(app(MailManager::class)->mailer('custom')->getSymfonyTransport())->toBeInstanceOf(NullTransport::class);
});

/**
 * Records the password of the transport it would be sent through, without actually sending anything.
 */
class PasswordRecordingMailable extends Mailable
{
    public static array $passwords = [];

    public function send($mailer)
    {
        // Same as in Mailable::send()
        $mailer = $mailer instanceof MailFactory ? $mailer->mailer($this->mailer) : $mailer;

        static::$passwords[] = invade($mailer->getSymfonyTransport())->password;

        return null;
    }
}

class PasswordRecordingNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): Mailable
    {
        return (new PasswordRecordingMailable)->to('foo@example.com');
    }
}
