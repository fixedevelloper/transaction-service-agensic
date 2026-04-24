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
        /**
         * ===============================
         * TABLE: payment_gateways
         * ===============================
         */
        Schema::create('payment_gateways', function (Blueprint $table) {
            $table->id();

            $table->string('name'); // Flutterwave
            $table->string('code')->unique(); // flutterwave
            $table->string('type')->nullable(); // fintech, bank_api, mobile_money

            $table->boolean('is_active')->default(true);

            $table->string('logo')->nullable();
            $table->string('website')->nullable();

            $table->json('credentials')->nullable(); // encrypted keys later
            $table->json('settings')->nullable();

            $table->timestamps();
        });

        /**
         * ============================================
         * TABLE: gateway_country_services
         * One row = 1 gateway + 1 country + 1 service
         * ============================================
         */
        Schema::create('gateway_country_services', function (Blueprint $table) {
            $table->id();

            $table->foreignId('gateway_id')
                ->constrained('payment_gateways')
                ->cascadeOnDelete();

            $table->string('country_code', 5); // CM, CI, SN
            $table->string('service_type', 30); // mobile, bank

            $table->boolean('is_enabled')->default(true);
            $table->boolean('is_default')->default(false);

            $table->unsignedInteger('priority')->default(1);

            $table->decimal('fixed_fee', 18, 2)->default(0);
            $table->decimal('percent_fee', 8, 4)->default(0);

            $table->decimal('min_amount', 18, 2)->nullable();
            $table->decimal('max_amount', 18, 2)->nullable();

            $table->string('currency', 10)->nullable();

            $table->json('meta')->nullable();

            $table->timestamps();

            $table->unique(
                ['gateway_id', 'country_code', 'service_type'],
                'gateway_country_service_unique'
            );

            $table->index(
                ['country_code', 'service_type', 'is_enabled'],
                'country_service_enabled_idx'
            );

            $table->index(
                ['gateway_id', 'is_enabled'],
                'gateway_enabled_idx'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gateway_country_services');
        Schema::dropIfExists('payment_gateways');
    }
};