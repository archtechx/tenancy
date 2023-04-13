<?php

use Doctum\Doctum;
use Symfony\Component\Finder\Finder;

$iterator = Finder::create()
    ->files()
    ->name('*.php')
    // ->exclude('Resources')
    // ->exclude('Tests')
    ->in('src/');

return new Doctum($iterator, [
    'title' => 'Tenancy for Laravel API Documentation',
    'base_url' => 'https://api.tenancyforlaravel.com/',
    'favicon' => 'https://tenancyforlaravel.com/favicon.ico',
]);
