<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Assistant du support sur WhatsApp : une ligne par message traité (répondu, passé à l'équipe, laissé), avec les
 * fiches citées et la consommation, pour le suivi dans la console et le plafond de coût par jour.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_agent_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('support_message_id')->nullable()->constrained()->nullOnDelete();
            $table->string('outcome', 20)->index();
            $table->string('reason', 200)->nullable();
            $table->json('articles')->nullable();
            $table->string('model', 60)->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->decimal('cost_usd', 8, 4)->default(0);
            $table->timestamps();
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_agent_runs');
    }
};
