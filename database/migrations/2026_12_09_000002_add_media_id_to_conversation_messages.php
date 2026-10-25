<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Photo reçue par le WhatsApp officiel : identifiant de média de Meta, lu à la demande (capture d'un live)
        Schema::table('conversation_messages', function (Blueprint $table) {
            $table->string('media_id', 128)->nullable()->after('provider_message_id');
        });
    }

    public function down(): void
    {
        Schema::table('conversation_messages', function (Blueprint $table) {
            $table->dropColumn('media_id');
        });
    }
};
