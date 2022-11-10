<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTenantResourcesTable extends Migration
{
    public function up()
    {
        Schema::create('tenant_resources', function (Blueprint $table) {
            $table->increments('id');
            $table->string('tenant_id');
            $table->string('resource_global_id');
            $table->string('tenant_resources_type');
        });
    }

    public function down()
    {
        Schema::dropIfExists('tenant_resources');
    }
}
