<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_node_executions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('workflow_runs')->cascadeOnDelete();
            $table->string('node_name');
            $table->unsignedTinyInteger('attempt')->default(1);
            $table->json('tags')->nullable();
            $table->timestamp('executed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_node_executions');
    }
};
