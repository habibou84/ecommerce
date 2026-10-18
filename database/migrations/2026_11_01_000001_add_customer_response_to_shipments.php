<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Réponse du client au message « votre livreur est en route » : il attend, ou il n'est pas disponible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->timestamp('customer_notified_at')->nullable();
            $table->string('customer_response', 20)->nullable(); // available, unavailable
            $table->timestamp('customer_response_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shipments', fn (Blueprint $table) => $table->dropColumn(['customer_notified_at', 'customer_response', 'customer_response_at']));
    }
};
