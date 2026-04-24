<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('senders', function (Blueprint $table) {
          $table->enum('account_type', ['P', 'B'])->nullable()->index();
            $table->date('date_birth')->nullable();
            $table->string('business_name', 150)->nullable();
            $table->string('business_type', 100)->nullable();
            $table->date('business_register_date')->nullable();
            $table->enum('civility', ['Maried', 'Single', 'Others'])->nullable()->index();
            $table->enum('gender', ['M', 'F', 'other'])->nullable()->index();
        
        });
        Schema::table('beneficiaries', function (Blueprint $table) {
           $table->enum('account_type', ['P', 'B'])->nullable()->index();
            $table->date('date_birth')->nullable();
            $table->string('business_name', 150)->nullable();
            $table->string('business_type', 100)->nullable();
            $table->date('business_register_date')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
