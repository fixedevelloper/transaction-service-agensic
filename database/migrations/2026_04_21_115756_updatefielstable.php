<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
        $table->enum('debit_status',['pending', 'processing', 'success','failed'])->default('pending')->nullable();
        $table->timestamp('processed_at')->nullable();
         $table->enum('status', ['pending', 'processing', 'success','failed'])->default('pending')->change();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
    
        });
    }
};