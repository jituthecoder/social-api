<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->onDelete('cascade');
            $table->foreignId('social_account_id')->constrained('social_accounts')->onDelete('cascade');
            $table->foreignId('post_id')->nullable()->constrained('posts')->onDelete('set null');
            $table->string('platform')->index();
            $table->string('metric_name')->index();
            $table->decimal('metric_value', 14, 2)->default(0);
            $table->timestamp('recorded_at')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('analytics_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->onDelete('cascade');
            $table->foreignId('social_account_id')->constrained('social_accounts')->onDelete('cascade');
            $table->string('platform')->index();
            $table->date('date')->index();
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('reach')->default(0);
            $table->unsignedBigInteger('likes')->default(0);
            $table->unsignedBigInteger('comments')->default(0);
            $table->unsignedBigInteger('shares')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->unsignedBigInteger('followers')->default(0);
            $table->timestamps();

            $table->unique(['workspace_id', 'social_account_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_daily');
        Schema::dropIfExists('analytics');
    }
};
