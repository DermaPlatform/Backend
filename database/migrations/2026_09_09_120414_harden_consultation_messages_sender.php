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
        Schema::table('consultation_messages', function (Blueprint $table): void {
            $table->dropForeign(['sender_id']);
        });

        Schema::table('consultation_messages', function (Blueprint $table): void {
            $table->foreign('sender_id')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('consultation_messages', function (Blueprint $table): void {
            $table->dropForeign(['sender_id']);
        });

        Schema::table('consultation_messages', function (Blueprint $table): void {
            $table->foreign('sender_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();
        });
    }
};
