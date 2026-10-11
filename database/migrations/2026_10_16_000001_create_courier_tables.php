<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Règles de rémunération des livreurs de la boutique
        Schema::create('courier_pay_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type', 20); // per_delivery | per_zone
            $table->unsignedInteger('amount')->default(0);          // par livraison (ou par défaut pour per_zone)
            $table->json('zone_amounts')->nullable();               // {delivery_zone_id: montant}
            $table->unsignedInteger('failed_attempt_amount')->default(0);
            $table->timestamps();
        });

        Schema::create('couriers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // compte de connexion à /livreur
            $table->string('name');
            $table->string('phone', 20);
            $table->string('vehicle_type', 20)->default('moto'); // moto | bicycle | car | foot
            $table->foreignId('pay_rule_id')->nullable()->constrained('courier_pay_rules')->nullOnDelete();
            $table->unsignedInteger('cash_limit')->nullable(); // alerte au-delà de ce montant détenu
            $table->boolean('is_available')->default(true);
            $table->string('status', 20)->default('invited'); // invited | active | inactive
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'phone']);
            $table->index(['store_id', 'status']);
        });

        Schema::create('courier_zones', function (Blueprint $table) {
            $table->foreignId('courier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('delivery_zone_id')->constrained()->cascadeOnDelete();

            $table->primary(['courier_id', 'delivery_zone_id']);
        });

        Schema::table('shipments', function (Blueprint $table) {
            $table->foreignId('courier_id')->nullable()->after('carrier_connection_id')->constrained()->nullOnDelete();
            $table->string('proof_photo_path')->nullable()->after('proof_photo_url');
            $table->date('rescheduled_to')->nullable()->after('failure_reason');
            $table->index(['courier_id', 'status']);
        });

        // Historique des missions, y compris réassignées
        Schema::create('courier_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('courier_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20); // assigned | completed | reassigned | cancelled
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at');
            $table->timestamp('completed_at')->nullable();

            $table->index(['courier_id', 'status']);
        });

        Schema::create('courier_remittances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('courier_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('expected_amount');
            $table->unsignedInteger('received_amount');
            $table->integer('difference'); // reçu − attendu
            $table->string('method', 20)->default('cash');
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamp('received_at');
        });

        // Argent encaissé par un livreur, détenu jusqu'à son versement à la boutique
        Schema::create('cash_collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('courier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('amount');
            $table->string('method', 20)->default('cash');
            $table->foreignId('remittance_id')->nullable()->constrained('courier_remittances')->nullOnDelete();
            $table->timestamp('collected_at');

            $table->index(['courier_id', 'remittance_id']);
        });

        Schema::create('courier_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('courier_id')->constrained()->cascadeOnDelete();
            $table->date('period_start')->nullable();
            $table->date('period_end');
            $table->integer('total');
            $table->string('status', 20)->default('draft'); // draft | paid | cancelled
            $table->string('method', 20)->nullable();
            $table->string('reference')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Journal de ce que la boutique doit à chaque livreur
        Schema::create('courier_earnings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('courier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shipment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20); // delivery | failed_attempt | bonus | adjustment | advance
            $table->integer('amount');   // négatif : avance ou retenue
            $table->foreignId('payout_id')->nullable()->constrained('courier_payouts')->nullOnDelete();
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');

            $table->index(['courier_id', 'payout_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courier_earnings');
        Schema::dropIfExists('courier_payouts');
        Schema::dropIfExists('cash_collections');
        Schema::dropIfExists('courier_remittances');
        Schema::dropIfExists('courier_assignments');
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropIndex(['courier_id', 'status']);
            $table->dropConstrainedForeignId('courier_id');
            $table->dropColumn(['proof_photo_path', 'rescheduled_to']);
        });
        Schema::dropIfExists('courier_zones');
        Schema::dropIfExists('couriers');
        Schema::dropIfExists('courier_pay_rules');
    }
};
