<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_generations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('prompt_type')->index(); // generate_post, rewrite_post, generate_variant, generate_hashtags, content_ideas
            $table->string('model');
            $table->text('input_prompt');
            $table->text('generated_content')->nullable();
            $table->unsignedInteger('token_usage')->default(0);
            $table->decimal('estimated_cost', 8, 4)->default(0);
            $table->string('status')->default('completed')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_generations');
    }
};
