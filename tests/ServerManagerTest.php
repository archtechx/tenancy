<?php

namespace Stancl\Tenancy\Tests;

use Stancl\Tenancy\ServerManager;

class ServerManagerTest extends TestCase
{
    public function setUp()
    {
        parent::setUp();

        $this->serverManager = app(ServerManager::class);
    }

    /** @test */
    public function getConfigFilePath_works_when_single_file_mode_is_used()
    {
        config([
            'tenancy.server.file.single' => true,
            'tenancy.server.file.path' => '/foo/bar',
        ]);
        
        $this->assertSame('/foo/bar', $this->serverManager->getConfigFilePath());
    }

    /** @test */
    public function getConfigFilePath_works_when_multiple_files_mode_is_used()
    {
        config([
            'tenancy.server.file.single' => false,
            'tenancy.server.file.path' => [
                'prefix' => '/etc/foo',
                'suffix' => 'bar'
            ],
        ]);

        $uuid = tenant('uuid');

        $this->assertSame("/etc/foo{$uuid}bar", $this->serverManager->getConfigFilePath());
    }
}
