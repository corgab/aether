<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quantum_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('task_arn')->unique();
            $table->string('driver');
            $table->string('status')->index();
            $table->json('circuit');
            $table->json('counts')->nullable();
            $table->integer('shots');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quantum_tasks');
    }
};
