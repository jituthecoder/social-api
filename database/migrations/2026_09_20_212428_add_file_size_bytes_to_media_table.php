<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            // Add file_size_bytes as alias/copy of size column (some code uses this name)
            if (!Schema::hasColumn('media', 'file_size_bytes')) {
                $table->unsignedBigInteger('file_size_bytes')->nullable()->after('size');
            }
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            if (Schema::hasColumn('media', 'file_size_bytes')) {
                $table->dropColumn('file_size_bytes');
            }
        });
    }
};
