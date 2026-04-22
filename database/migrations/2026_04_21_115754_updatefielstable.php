<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gateways', function (Blueprint $table) {
           $table->dropIndex(['method']); 
    
    // On le recrée
    $table->string('method')->index()->change();
        });
    }

    public function down(): void
    {
        Schema::table('gateways', function (Blueprint $table) {
            // On retire l'index en cas de rollback
            $table->dropIndex(['method']);
        });
    }
};