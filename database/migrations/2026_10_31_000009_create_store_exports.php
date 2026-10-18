<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Exports complets des données d'une boutique (archive ZIP de fichiers CSV), téléchargeables 7 jours.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('pending'); // pending, ready, failed
            $table->string('path')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('error')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index(['store_id', 'created_at']);
        });

        Schema::table('stores', function (Blueprint $table) {
            $table->string('closed_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('stores', fn (Blueprint $table) => $table->dropColumn('closed_reason'));
        Schema::dropIfExists('store_exports');
    }
};
