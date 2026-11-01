<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Un seul code par produit dans le live (la robe = A1), ses variantes (tailles, couleurs) partagent le code
        Schema::table('live_items', function (Blueprint $table) {
            $table->dropUnique(['live_session_id', 'code']);
            $table->index(['live_session_id', 'code']);
            $table->unique(['live_session_id', 'variant_id']);
        });
    }

    public function down(): void
    {
        Schema::table('live_items', function (Blueprint $table) {
            $table->dropUnique(['live_session_id', 'variant_id']);
            $table->dropIndex(['live_session_id', 'code']);
            $table->unique(['live_session_id', 'code']);
        });
    }
};
