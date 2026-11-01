<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Vente flash pendant le live : prix live pour quelques minutes ; la réservation garde le prix du moment
        Schema::table('live_items', function (Blueprint $table) {
            $table->unsignedInteger('flash_price')->nullable()->after('price');
            $table->timestamp('flash_ends_at')->nullable()->after('flash_price');
        });
        Schema::table('live_reservations', function (Blueprint $table) {
            $table->unsignedInteger('unit_price')->nullable()->after('live_item_id');
        });
    }

    public function down(): void
    {
        Schema::table('live_items', fn (Blueprint $table) => $table->dropColumn(['flash_price', 'flash_ends_at']));
        Schema::table('live_reservations', fn (Blueprint $table) => $table->dropColumn('unit_price'));
    }
};
