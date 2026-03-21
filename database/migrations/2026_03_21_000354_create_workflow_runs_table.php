<?php

use Cainy\Laragraph\Enums\RunStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('workflow_runs', function (Blueprint $table) {
            $table->id();
            $table->string('key')->nullable();
            $table->json('snapshot')->nullable();
            $table->json('state');
            $table->string('status');
            $table->string('current');
            $table->json('active_pointers')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_runs');
    }
};
