<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lien secret du flux catalogue (Facebook, Instagram, WhatsApp, Google) : Meta le relit automatiquement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->string('catalog_feed_token', 40)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropUnique(['catalog_feed_token']);
            $table->dropColumn('catalog_feed_token');
        });
    }
};
