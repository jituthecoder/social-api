<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('publish_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('posts')->onDelete('cascade');
            $table->foreignId('post_variant_id')->nullable()->constrained('post_variants')->onDelete('cascade');
            $table->foreignId('social_account_id')->constrained('social_accounts')->onDelete('cascade');
            $table->string('status')->index(); // success, failed, rate_limited, token_expired
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->json('raw_response')->nullable();
            $table->timestamp('attempted_at')->useCurrent()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publish_attempts');
    }
};
