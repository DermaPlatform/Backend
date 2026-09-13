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
        Schema::table('services', function (Blueprint $table): void {
            $table->decimal('price', 10, 2)->nullable()->change();

            $table->integer('default_duration_minutes')
                ->default(30)
                ->after('price');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            $table->dropColumn('default_duration_minutes');

            $table->decimal('price', 10, 2)
                ->nullable(false)
                ->change();
        });
    }
};
