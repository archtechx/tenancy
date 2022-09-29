<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class TestCreateTenantCentralUsersPivotTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('tenant_central_users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('tenant_id');
            $table->string('global_user_id');

            $table->unique(['tenant_id', 'global_user_id']);

            $table->foreign('tenant_id')->references('id')->on('tenants')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('global_user_id')->references('global_id')->on('central_users')->onUpdate('cascade')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('tenant_central_users');
    }
}
