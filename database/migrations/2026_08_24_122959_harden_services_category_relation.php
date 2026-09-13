<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Categories are soft deleted, but the original foreign key cascaded on delete.
 * A hard delete of a category therefore wiped every one of its services out of
 * the database, soft deleted rows included. Restricting the delete keeps the
 * booking history intact, which US-008 requires.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            $table->dropForeign(['category_id']);

            $table->foreign('category_id')
                ->references('id')
                ->on('service_categories')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            $table->dropForeign(['category_id']);

            $table->foreign('category_id')
                ->references('id')
                ->on('service_categories')
                ->cascadeOnDelete();
        });
    }
};
