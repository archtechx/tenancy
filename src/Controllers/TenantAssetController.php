<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Controllers;

use Closure;
use Exception;
use Illuminate\Filesystem\LocalFilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Storage;
use Stancl\Tenancy\Bootstrappers\FilesystemTenancyBootstrapper;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * Serves files from app/public inside the tenant's storage directory, or from the root
 * of the $publicDisk when the property is set.
 *
 * Requires FilesystemTenancyBootstrapper to be enabled, so that writes to the default
 * public disk end up in the app/public within the *tenant's* storage, or so that the
 * public disk set in the static property is similarly scoped.
 *
 * @see FilesystemTenancyBootstrapper
 */
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
     *
     * The disk has to be local, since the assets are read from the filesystem. Disks using the
     * 'scoped' driver are supported as long as the disk they're based on uses the 'local' driver.
     *
     * The disk also has to be listed in tenancy.filesystem.disks -- for scoped disks, it's the
     * disk they're based on that has to be listed there (since a scoped disk inherits its root).
     * FilesystemTenancyBootstrapper only scopes the roots of disks listed there, so
     * without that every tenant would be served the same (central) directory.
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
     * Directory the assets are served from -- the root of the $publicDisk, or app/public
     * inside the tenant's storage directory when no disk is configured. With no disk and
     * no current tenant (e.g. on a universal route), the central app/public is used.
     *
     * The tenant's storage directory is resolved using the FilesystemTenancyBootstrapper (rather
     * than storage_path(), so that it's tenant-scoped regardless of the suffix_storage_path config).
     */
    protected function assetRoot(): string
    {
        if (static::$publicDisk) {
            $disk = Storage::disk(static::$publicDisk);

            if (! $disk instanceof LocalFilesystemAdapter) {
                throw new Exception('Disk [' . static::$publicDisk . '] is not a local disk. Only local disks can be used for serving assets.');
            }

            $baseDiskName = FilesystemTenancyBootstrapper::baseDiskName(static::$publicDisk);

            if ($baseDiskName === null) {
                throw new Exception('Disk [' . static::$publicDisk . '] has an unnamed parent disk. Use a named parent disk listed in tenancy.filesystem.disks.');
            }

            if (! in_array($baseDiskName, config('tenancy.filesystem.disks'), true)) {
                // FilesystemTenancyBootstrapper only scopes the roots of disks listed in tenancy.filesystem.disks.
                // Without that, the root stays central and every tenant would be served the same directory.
                throw new Exception("Disk [$baseDiskName] is not tenant-aware. Add it to the tenancy.filesystem.disks config to make its root tenant-specific.");
            }

            // The root is read from the resolved disk rather than from the disk's config, since the
            // config root isn't the full root path of every local disk. Disks using the 'scoped' driver
            // have no root in their config, and a 'prefix' is part of the root path as well.
            return rtrim($disk->path(''), DIRECTORY_SEPARATOR);
        }

        if ($tenant = tenant()) {
            return FilesystemTenancyBootstrapper::getTenantStoragePath($tenant) . '/app/public';
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

        // realpath() ensures the directory exists and converts / to \ on Windows
        $attemptedPath = realpath("{$allowedRoot}/{$path}");

        // User is attempting to access a nonexistent file
        $this->abortIf($attemptedPath === false, 'Accessing a nonexistent file');

        // User is attempting to access a file outside the $allowedRoot folder.
        // The trailing separator is needed so that sibling directories that
        // start with the same name (e.g. app/public-private) aren't accepted.
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
