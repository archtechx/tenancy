<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_resources', function (Blueprint $table) {
            $table->increments('id');
            $table->string('tenant_id');
            $table->string('resource_global_id');
            $table->string('tenant_resources_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_resources');
    }
};
