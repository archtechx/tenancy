<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests;

use Stancl\Tenancy\Tenant;

class ReidentificationTest extends TestCase
{
    public $autoInitTenancy = false;
    /**
     * These tests are run when a tenant is identified after another tenant has already been identified.
     */

    /** @test */
    public function storage_facade_roots_are_correct()
    {
        $originals = [];

        foreach (config('tenancy.filesystem.disks') as $disk) {
            $originals[$disk] = config("filesystems.disks.{$disk}.root");
        }

        tenancy()->init('test.localhost');
        Tenant::new()->withDomains(['second.localhost'])->save();
        tenancy()->init('second.localhost');

        foreach (config('tenancy.filesystem.disks') as $disk) {
            $suffix = config('tenancy.filesystem.suffix_base') . tenant('id');
            $current_path_prefix = \Storage::disk($disk)->getAdapter()->getPathPrefix();

            if ($override = config("tenancy.filesystem.root_override.{$disk}")) {
                $correct_path_prefix = str_replace('%storage_path%', storage_path(), $override);
            } else {
                if ($base = $originals[$disk]) {
                    $correct_path_prefix = $base . "/$suffix/";
                } else {
                    $correct_path_prefix = "$suffix/";
                }
            }

            $this->assertSame($correct_path_prefix, $current_path_prefix);
        }
    }

    /** @test */
    public function storage_path_is_correct()
    {
        $original = storage_path();

        tenancy()->init('test.localhost');
        Tenant::new()->withDomains(['second.localhost'])->save();
        tenancy()->init('second.localhost');

        $suffix = config('tenancy.filesystem.suffix_base') . tenant('id');
        $this->assertSame($original . "/$suffix", storage_path());
    }
}
