<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remise des retours par un livreur de Jibiat (contrat socle 1.14) : copie de la remise que le marchand confirme
 * (« Tout reçu ») ou conteste (« Il en manque ») depuis Jibiat Marchand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carrier_return_handovers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained('carrier_connections')->cascadeOnDelete();
            // Code de la remise chez l'entreprise (« R-4821 »)
            $table->string('reference', 20);
            // pending, expired, confirmed, unconfirmed, disputed, resolved, cancelled ; closed : plus listée par l'entreprise
            $table->string('status', 20);
            $table->string('courier_name')->nullable();
            $table->string('received_by_name')->nullable();
            // Colis : tracking_code, recipient_name, status
            $table->json('parcels');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('handed_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->string('confirmed_via', 30)->nullable();
            $table->timestamp('disputed_at')->nullable();
            $table->timestamps();

            $table->unique(['connection_id', 'reference']);
            $table->index(['store_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carrier_return_handovers');
    }
};
