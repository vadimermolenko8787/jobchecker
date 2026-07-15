<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->json('value')->nullable();
            $table->timestamps();
        });

        Schema::create('resumes', function (Blueprint $table) {
            $table->id();
            $table->string('original_name');
            $table->string('path');
            $table->mediumText('text')->nullable();
            $table->json('keywords')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('runs', function (Blueprint $table) {
            $table->id();
            $table->string('trigger', 20)->default('manual');
            $table->string('status', 20)->default('running');
            $table->json('stats')->nullable();
            $table->mediumText('log')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('vacancies', function (Blueprint $table) {
            $table->id();
            $table->string('source', 30);
            $table->string('external_id');
            $table->string('title', 500);
            $table->string('company', 300)->nullable();
            $table->string('location', 300)->nullable();
            $table->string('url', 1000);
            $table->mediumText('description')->nullable();
            $table->string('salary')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->json('raw')->nullable();
            $table->string('status', 20)->default('new');
            $table->unsignedTinyInteger('score')->nullable();
            $table->text('score_reason')->nullable();
            $table->string('resume_path')->nullable();
            $table->string('cover_letter_path')->nullable();
            $table->foreignId('run_id')->nullable()->constrained('runs')->nullOnDelete();
            $table->timestamps();
            $table->unique(['source', 'external_id']);
            $table->index('status');
            $table->index('score');
        });

        Schema::create('fetch_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->nullable()->constrained('runs')->cascadeOnDelete();
            $table->string('source', 30);
            $table->json('request')->nullable();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->mediumText('response_body')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fetch_logs');
        Schema::dropIfExists('vacancies');
        Schema::dropIfExists('runs');
        Schema::dropIfExists('resumes');
        Schema::dropIfExists('settings');
    }
};
