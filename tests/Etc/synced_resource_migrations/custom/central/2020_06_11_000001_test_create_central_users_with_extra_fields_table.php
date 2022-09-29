<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class TestCreateCentralUsersWithExtraFieldsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('central_users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('global_id')->unique();
            $table->string('name');
            $table->string('email');
            $table->string('password');

            // extra columns
            $table->string('role');
            $table->string('code');
        });
    }

    public function down()
    {
        Schema::dropIfExists('central_users');
    }
}