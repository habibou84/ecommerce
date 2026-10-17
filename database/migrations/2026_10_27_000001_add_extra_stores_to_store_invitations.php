<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invitation valable pour plusieurs boutiques de la même adresse : autres boutiques et rôle dans chacune.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_invitations', function (Blueprint $table) {
            $table->json('extra_stores')->nullable()->after('role'); // [{store_id, role}]
        });
    }

    public function down(): void
    {
        Schema::table('store_invitations', function (Blueprint $table) {
            $table->dropColumn('extra_stores');
        });
    }
};
