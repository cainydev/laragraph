<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_node_executions', function (Blueprint $table) {
            $table->string('error_class')->nullable()->after('tags');
            $table->text('error_message')->nullable()->after('error_class');
            $table->longText('error_trace')->nullable()->after('error_message');
            $table->timestamp('failed_at')->nullable()->after('executed_at');
        });
    }

    public function down(): void
    {
        Schema::table('workflow_node_executions', function (Blueprint $table) {
            $table->dropColumn(['error_class', 'error_message', 'error_trace', 'failed_at']);
        });
    }
};
