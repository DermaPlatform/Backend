<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * US-008 asks for "delete OR deactivate". Soft deleting removes a service from
 * the catalog for good, whereas deactivating hides it while keeping it easy to
 * bring back, so the two need separate columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true)->after('price');

            $table->index(['category_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            $table->dropIndex(['category_id', 'is_active']);
            $table->dropColumn('is_active');
        });
    }
};
