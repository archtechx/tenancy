<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTenantUsersTable extends Migration
{
    public function up()
    {
        Schema::create('tenant_users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('tenant_id');
            $table->string('global_user_id');

            $table->unique(['tenant_id', 'global_user_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('tenant_users');
    }
}
