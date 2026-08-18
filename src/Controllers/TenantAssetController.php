<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Controllers;

use Closure;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Stancl\Tenancy\Bootstrappers\FilesystemTenancyBootstrapper;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class TenantAssetController implements HasMiddleware
{
    /**
     * Used for adding custom headers to the response.
     *
     * @var (Closure(Request): array)|array
     */
    public static Closure|array $headers = [];

    /**
     * Additional middleware to be used on the route to this controller.
     *
     * @var array<string>
     */
    public static array $middleware = [];

    /**
     * Disk the assets are served from.
     *
     * When null, the assets are served from app/public inside the tenant's storage directory.
     */
    public static string|null $publicDisk = null;

    public static function middleware()
    {
        return array_map(
            fn ($middleware) => new Middleware($middleware),
            array_merge(
                [tenancy()->defaultMiddleware()],
                static::$middleware,
            ),
        );
    }

    /**
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
     */
    public function __invoke(Request $request, ?string $path = null): BinaryFileResponse
    {
        $this->validatePath($path);

        try {
            $headers = static::$headers instanceof Closure
                ? (static::$headers)($request)
                : static::$headers;

            return response()->file($this->assetRoot() . "/$path", $headers);
        } catch (Throwable) {
            abort(404);
        }
    }

    /**
     * Directory the assets are served from -- the root of the $publicDisk,
     * or app/public inside the tenant's storage directory when no disk is configured.
     *
     * The storage directory is resolved using the FilesystemTenancyBootstrapper (rather than
     * storage_path(), so that it's tenant-scoped regardless of the suffix_storage_path config).
     */
    protected function assetRoot(): string
    {
        if (static::$publicDisk) {
            $diskRoot = config('filesystems.disks.' . static::$publicDisk . '.root');

            if (! is_string($diskRoot)) {
                // A disk with no root path would let the controller serve any file in the app
                throw new Exception('Disk [' . static::$publicDisk . '] has no root path configured.');
            }

            return rtrim($diskRoot, '/');
        }

        if ($tenant = tenant()) {
            return FilesystemTenancyBootstrapper::getBoundTenantStoragePath($tenant) . '/app/public';
        }

        return storage_path('app/public');
    }

    /**
     * Prevent path traversal attacks. This is generally a non-issue on modern
     * webservers but it's still worth handling on the application level as well.
     *
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException
     */
    protected function validatePath(string|null $path): void
    {
        $this->abortIf($path === null, 'Empty path');

        $allowedRoot = realpath($this->assetRoot());

        // The asset root doesn't exist, so it cannot contain files
        $this->abortIf($allowedRoot === false, "Storage root doesn't exist");

        $attemptedPath = realpath("{$allowedRoot}/{$path}");

        // User is attempting to access a nonexistent file
        $this->abortIf($attemptedPath === false, 'Accessing a nonexistent file');

        // User is attempting to access a file outside the $allowedRoot folder.
        // The trailing separator is needed so that sibling directories that
        // start with the same name (e.g. app/public-private) don't pass.
        $this->abortIf(! str($attemptedPath)->startsWith(rtrim($allowedRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR), 'Accessing a file outside the storage root');
    }

    /** @return void|never */
    protected function abortIf(bool $condition, string $exceptionMessage = ''): void
    {
        if ($condition) {
            if (app()->runningUnitTests()) {
                // Makes testing the cause of the failure in validatePath() easier
                throw new Exception($exceptionMessage);
            }

            // We always use 404 to avoid leaking information about the cause of the error
            // e.g. when someone is trying to access a nonexistent file outside of the allowed
            // root folder, we don't want to let the user know whether such a file exists or not.
            abort(404);
        }
    }
}
