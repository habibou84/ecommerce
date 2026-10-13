<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Boîte « Messages » : conversations avec les clients sur WhatsApp (puis Messenger, Instagram), messages reçus et
 * réponses de l'équipe, et commande créée depuis la conversation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 20); // whatsapp
            $table->string('contact', 64); // numéro (+225…) pour WhatsApp
            $table->string('contact_name', 120)->nullable();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete(); // dernière commande créée depuis la conversation
            $table->string('last_preview', 200)->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('last_inbound_at')->nullable();
            $table->unsignedInteger('unread_count')->default(0);
            $table->timestamps();

            $table->unique(['store_id', 'channel', 'contact']);
            $table->index(['store_id', 'last_message_at']);
        });

        Schema::create('conversation_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('direction', 3); // in | out
            $table->string('type', 20); // text | image | audio | video | document | location | sticker | button | template | other
            $table->text('body')->nullable();
            $table->string('provider_message_id')->nullable();
            $table->string('status', 20); // received | sent | delivered | read | failed
            $table->string('error')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // membre de l'équipe qui a répondu
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->index(['conversation_id', 'sent_at']);
            $table->index('provider_message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_messages');
        Schema::dropIfExists('conversations');
    }
};
