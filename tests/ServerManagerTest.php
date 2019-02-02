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

    /** @test */
    public function vhost_is_written()
    {
        [$tmpfile, $path, $vhost] = $this->setupCreateVhost();

        $this->serverManager->createVhost('localhost');

        $vhost = str_replace('%host%', 'localhost', $vhost);

        $this->assertContains($vhost, fread($tmpfile, filesize($path)));
    }

    /** @test */
    public function cert_is_deployed()
    {
        [$tmpfile, $path, $vhost] = $this->setupCreateVhost();

        $this->serverManager->createVhost('localhost');

        dump(fread($tmpfile, filesize($path)));
        // todo
    }

    public function setupCreateVhost()
    {
        $tmpfile = tmpfile();
        $path = stream_get_meta_data($tmpfile)['uri'];

        $vhost = "server {
    include includes/tenancy;
    server_name %host%;
}";

        config([
            'tenancy.server.nginx' => [
                'vhost' => $vhost,
                'extra_certbot_args' => [
                    '--staging'
                ],
            ],
            'tenancy.server.file' => [
                'single' => true,
                'path' => $path,
            ],
        ]);

        return [$tmpfile, $path, $vhost];
    }
}
