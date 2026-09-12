<?php

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
        Schema::create('doctor_service', function (Blueprint $table): void {
            $table->foreignId('doctor_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->foreignId('service_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->decimal('price', 10, 2);
            $table->integer('duration_minutes');
            $table->boolean('is_active');

            $table->unique(['doctor_id', 'service_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('doctor_service');
    }
};
