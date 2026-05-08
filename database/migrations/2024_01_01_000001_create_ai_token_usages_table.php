<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_token_usages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('agent');
            $table->string('model');
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('cache_write_tokens')->default(0);
            $table->unsignedInteger('cache_read_tokens')->default(0);
            $table->uuid('source_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_token_usages');
    }
};
