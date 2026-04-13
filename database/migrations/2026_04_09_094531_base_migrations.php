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
        Schema::create('senders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('country')->nullable();
            $table->string('address')->nullable();
            $table->string('identification_number')->nullable();
            $table->string('identification_type')->nullable();
            $table->string('identification_expired')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();

            $table->index('user_id');
        });
        Schema::create('beneficiaries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('code')->nullable();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('bank_account')->nullable();
            $table->string('mobile_wallet')->nullable();
            $table->string('country')->nullable();
            $table->string('city')->nullable();
            $table->string('address')->nullable();
            $table->string('identification_number')->nullable();
            $table->string('identification_type')->nullable();
            $table->string('identification_expired')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();

            $table->index('user_id');
        });
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sender_id');
            $table->unsignedBigInteger('beneficiary_id');
            $table->decimal('amount', 15, 2);
            $table->enum('type', ['bank', 'mobile'])->default('mobile');
            $table->enum('status', ['pending', 'completed', 'failed'])->default('pending');
            $table->string('reference')->unique();
            $table->string('currency', 3)->default('XAF');
            $table->string('provider')->nullable();
            $table->string('provider_token')->nullable();
            $table->json('meta_data')->nullable();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('initiated_by')->nullable();
            $table->timestamps();

            $table->foreign('sender_id')->references('id')->on('senders')->onDelete('cascade');
            $table->foreign('beneficiary_id')->references('id')->on('beneficiaries')->onDelete('cascade');
        });
        Schema::create('ledger', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->enum('type', ['credit', 'debit']);
            $table->decimal('amount', 15, 2);
            $table->decimal('balance_before', 15, 2);
            $table->decimal('balance_after', 15, 2);
            $table->string('description')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->foreign('transaction_id')->references('id')->on('transactions')->onDelete('set null');
        });

        Schema::create('payercodes', function (Blueprint $table) {
            $table->id();

            $table->string('payer_code')->nullable();
            $table->string('country_code');
            $table->integer('country_id')->nullable();
            $table->string('service_name')->nullable();

            $table->string('service_code');

            $table->timestamps();

            // 🔥 performance + anti doublon
            $table->index(['country_code', 'service_code']);
           // $table->unique(['payer_code', 'country_code', 'service_code']);
        });

        Schema::create('wace_data', function (Blueprint $table) {
            $table->id();

            // 🔹 Infos du service
            $table->string('name', 150)->nullable()->index();       // Nom du service
            $table->string('service', 100)->nullable()->index();    // Code ou identifiant
            $table->string('type', 50)->nullable()->index();        // Type de service (ex: mobile_money, bank, etc.)

            $table->timestamps();

            // 🔐 Index composite pour recherches fréquentes
            $table->index(['service', 'type']);
        });
        Schema::create('gateways', function (Blueprint $table) {
            $table->id();

            $table->string('name', 150);
            $table->string('code', 100)->nullable()->unique()->index();
            $table->string('logo', 255)->nullable();
            $table->enum('method', ['api', 'ussd', 'manual'])->index();
            $table->enum('type', ['mobile_money', 'bank', 'card'])->index();
            $table->string('bank_id', 100)->nullable()->index();
            // 🌍 payer_codes
            $table->foreignId('payer_code_id')
                ->nullable()
                ->constrained('payer_codes')
                ->nullOnDelete()
                ->index()
                ->name('gateways_payer_code_id_fk');

            // ⚙️ Statut
            $table->boolean('is_active')->default(true)->index();

            $table->timestamps();
        });
        Schema::create('payment_links', function (Blueprint $table) {
            $table->id();

            // 🔗 Identification
            $table->string("code")->unique();
            $table->unsignedBigInteger('user_id')->index();

            // 💰 Montants
            $table->decimal("amount", 15, 2)->default(0);
            $table->decimal("fees", 15, 2)->default(0);

            // 🌍 Contexte
            $table->string('country_code', 5)->index();
            $table->string('currency', 10)->default('XAF');

            // 📝 Infos générales
            $table->string("name")->nullable();
            $table->text("description")->nullable();

            // 👤 Sender (créateur du lien)
            $table->json("sender")->nullable();

            // 👤 Customer (payeur réel)
            $table->json("customer")->nullable();

            // 🔄 Status du paiement
            $table->enum('status', [
                'pending',
                'paid',
                'failed',
                'expired',
                'cancelled'
            ])->default('pending')->index();

            // ⏱️ Tracking temps
            $table->timestamp("submitted_at")->nullable(); // paiement effectué
            $table->timestamp("expires_at")->nullable();   // expiration du lien
            $table->timestamp("cancelled_at")->nullable();

            // 🔗 Provider paiement
            $table->string("provider")->nullable();
            $table->string("provider_token")->nullable();

            // 🌐 Payment context
            $table->string("payment_method")->nullable(); // mtn, orange, card...
            $table->string("channel")->nullable(); // mobile_money, card, etc.

            // 🔁 External tracking
            $table->string("reference")->nullable()->index();

            // 📦 Flexible data
            $table->json("metadata")->nullable();

            // 🔐 Security / retry
            $table->string("secure_token")->nullable()->unique();
            $table->integer("retry_count")->default(0);

            // 🧠 Soft delete
            $table->softDeletes();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('senders');
        Schema::dropIfExists('beneficiaries');
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('ledger');
    }
};
