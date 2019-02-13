<?php

namespace Stancl\Tenancy\Tests;

class CacheManagerTest extends TestCase
{
    /** @test */
    public function default_tag_is_automatically_applied()
    {
        $this->assertArraySubset([config('tenancy.cache.tag_base') . tenant('uuid')], cache()->tags('foo')->getTags()->getNames());
    }

    /** @test */
    public function tags_are_merged_when_array_is_passed()
    {
        $expected = [config('tenancy.cache.tag_base') . tenant('uuid'), 'foo', 'bar'];
        $this->assertEquals($expected, cache()->tags(['foo', 'bar'])->getTags()->getNames());
    }

    /** @test */
    public function tags_are_merged_when_string_is_passed()
    {
        $expected = [config('tenancy.cache.tag_base') . tenant('uuid'), 'foo'];
        $this->assertEquals($expected, cache()->tags('foo')->getTags()->getNames());
    }

    /** @test */
    public function exception_is_thrown_when_zero_arguments_are_passed_to_tags_method()
    {
        $this->expectException(\Exception::class);
        cache()->tags();
    }

    /** @test */
    public function exception_is_thrown_when_more_than_one_argument_is_passed_to_tags_method()
    {
        $this->expectException(\Exception::class);
        cache()->tags(1, 2);
    }
}
