<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Messages automatiques riches visibles dans Conversations : photo envoyée (adresse publique) et choix proposés
     * (boutons, lignes d'une liste).
     */
    public function up(): void
    {
        Schema::table('conversation_messages', function (Blueprint $table) {
            $table->string('media_url', 1000)->nullable()->after('media_id');
            $table->json('options')->nullable()->after('media_url');
        });
    }

    public function down(): void
    {
        Schema::table('conversation_messages', function (Blueprint $table) {
            $table->dropColumn(['media_url', 'options']);
        });
    }
};
