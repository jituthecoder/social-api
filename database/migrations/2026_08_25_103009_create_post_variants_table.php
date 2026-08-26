<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('posts')->onDelete('cascade');
            $table->foreignId('social_account_id')->nullable()->constrained('social_accounts')->onDelete('cascade');
            $table->string('platform')->index();
            $table->text('content');
            $table->json('hashtags')->nullable();
            $table->json('metadata')->nullable();
            $table->string('status')->default('pending')->index(); // pending, publishing, published, failed
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_variants');
    }
};
