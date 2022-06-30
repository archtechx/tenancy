<?php

namespace Stancl\Tenancy\Tests\Etc;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TestSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        DB::table('users')->insert([
            'name' => 'Seeded User',
            'email' => 'seeded@user',
            'password' => bcrypt('password'),
        ]);
    }
}
