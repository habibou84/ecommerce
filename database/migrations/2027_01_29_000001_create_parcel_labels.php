<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Étiquettes pré-imprimées : un lot imprimé d'avance (mini 40 × 30, planche A4 ou grande 10 × 15), chaque
     * étiquette avec un code unique impossible à deviner, reliée plus tard à une commande en la scannant.
     */
    public function up(): void
    {
        Schema::create('parcel_label_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('format', 10);
            $table->unsignedInteger('quantity');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['store_id', 'id']);
        });

        Schema::create('parcel_labels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('batch_id')->constrained('parcel_label_batches')->cascadeOnDelete();
            $table->string('code', 8)->unique();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['store_id', 'order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parcel_labels');
        Schema::dropIfExists('parcel_label_batches');
    }
};
