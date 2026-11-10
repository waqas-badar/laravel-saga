<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $tableName = config('saga.repositories.database.table', 'sagas');

        if (!Schema::hasTable($tableName)) {
            Schema::create($tableName, function (Blueprint $table) {
                $table->string('saga_id', 64)->primary();
                $table->string('saga_type', 128)->index();
                $table->string('status', 32)->index();
                $table->json('context');
                $table->string('current_step', 128)->nullable();
                $table->json('completed_steps');
                $table->text('exit_reason')->nullable();
                $table->dateTime('created_at')->index();
                $table->dateTime('updated_at');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tableName = config('saga.repositories.database.table', 'sagas');

        Schema::dropIfExists($tableName);
    }
};

