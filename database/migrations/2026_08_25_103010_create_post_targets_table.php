<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('posts')->onDelete('cascade');
            $table->foreignId('social_account_id')->constrained('social_accounts')->onDelete('cascade');
            $table->string('status')->default('pending')->index();
            $table->timestamps();

            $table->unique(['post_id', 'social_account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_targets');
    }
};
