<?php

require __DIR__.'/vendor/autoload.php';

use Doctum\Doctum;
use Symfony\Component\Finder\Finder;
use Doctum\Version\GitVersionCollection;
use Doctum\RemoteRepository\GitHubRemoteRepository;

$iterator = Finder::create()
    ->files()
    ->name('*.php')
    ->in($dir = __DIR__ . '/../src');

$versions = GitVersionCollection::create($dir)
    ->add('1.x', 'Tenancy 1.x')
    ->add('2.x', 'Tenancy 2.x')
    ->add('3.x', 'Tenancy 3.x')
    ->add('master', 'Tenancy Dev');

return new Doctum($iterator, [
    'title' => 'Tenancy for Laravel API Documentation',
    'versions' => $versions,
    'build_dir' => __DIR__ . '/build/%version%',
    'cache_dir' => __DIR__ . '/cache/%version%',
    'default_opened_level' => 2,
    'base_url' => 'https://api.tenancyforlaravel.com/',
    'favicon' => 'https://tenancyforlaravel.com/favicon.ico',
    'remote_repository' => new GitHubRemoteRepository('archtechx/tenancy', dirname($dir)),
]);
