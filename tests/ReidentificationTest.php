<?php

namespace Stancl\Tenancy\Tests;

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

        tenancy()->init('localhost');
        tenant()->create('second.localhost');
        tenancy()->init('second.localhost');

        foreach (config('tenancy.filesystem.disks') as $disk) {
            $suffix = config('tenancy.filesystem.suffix_base') . tenant('uuid');
            $current_path_prefix = \Storage::disk($disk)->getAdapter()->getPathPrefix();
            $this->assertSame($originals[$disk] . "/$suffix/", $current_path_prefix);
        }
    }

    /** @test */
    public function storage_path_is_correct()
    {
        $original = storage_path();

        tenancy()->init('localhost');
        tenant()->create('second.localhost');
        tenancy()->init('second.localhost');


        $suffix = config('tenancy.filesystem.suffix_base') . tenant('uuid');
        $this->assertSame($original . "/$suffix", storage_path());
    }
}
