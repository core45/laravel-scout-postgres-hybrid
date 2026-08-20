<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tables for the test fixtures only. Never published.
 *
 * `tenants` exists so the scope foreign key has a real target in `column` mode:
 * the package's own migration emits `foreign()->references('id')->on(...)`, and a
 * missing table would fail at migrate time rather than proving anything about
 * the scope abstraction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('articles', function (Blueprint $table): void {
            $table->id();
            // Present in both scope modes. The column being unused under
            // `mode => 'none'` costs nothing, and keeping one fixture schema
            // means a single-tenant run exercises the same models.
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('locale', 10)->default('en');
            $table->boolean('published')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('articles');
        Schema::dropIfExists('tenants');
    }
};
