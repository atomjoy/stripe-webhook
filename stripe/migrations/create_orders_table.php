<?php

use App\Enums\VatRate;
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
        // Tabela główna zamówienia
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('order_number')->unique();
            $table->string('status', 30)->default('new')->index();
            $table->string('coupon_code')->nullable();
            $table->boolean('wants_invoice')->default(false);
            $table->text('notes')->nullable();

            // Podsumowanie finansowe (w groszach - pasuje do OrderCalculator['summary'])
            $table->unsignedInteger('subtotal')->default(0);
            $table->unsignedInteger('discount_amount')->default(0);
            $table->unsignedInteger('shipping_amount')->default(0);
            $table->unsignedInteger('pack_amount')->default(0);
            $table->unsignedInteger('total')->default(0);

            // Dane klienta i dostawy
            $table->string('customer_name');
            $table->string('customer_email')->index();
            $table->string('customer_phone', 30);

            // Dla firmy
            $table->string('company_name', 150)->nullable();
            $table->string('company_nip', 30)->nullable();

            // Dostawa adres
            $table->string('shipping_city', 100)->nullable();
            $table->string('shipping_zip', 20)->nullable();
            $table->string('shipping_address')->nullable();
            $table->string('shipping_hour')->nullable();

            // Dostawa kurier
            $table->string('shipping_method')->nullable();
            $table->string('shipping_method_name')->default('Dostawa'); // np. 'Paczkomat InPost 24/7'
            $table->string('shipping_locker_code', 30)->nullable(); // np. 'WAW123M'
            $table->string('shipping_locker_details')->nullable(); // np. 'ul. Marszałkowska 1, Warszawa'
            $table->unsignedSmallInteger('shipping_tax_rate')->default(VatRate::DEFAULT->value);

            // Inpost tracking
            $table->string('inpost_shipment_id')->nullable();
            $table->string('tracking_number')->nullable();

            // Stripe
            $table->string('payment_method')->default('stripe')->index();
            $table->string('payment_status')->default('pending')->index(); // Payment: pending, paid, expired, partially_refunded, refunded, disputed
            $table->string('payment_session_id')->nullable()->index(); // Dla payment
            $table->string('payment_intent_id')->nullable()->index(); // Dla refund
            $table->string('payment_error')->nullable();

            $table->timestamps();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamp('packing_started_at')->nullable();

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('shipping_method_id')->nullable()->constrained('shipping_methods')->nullOnDelete();
        });

        // Tabela pozycji zamówienia (szczegóły zakupów)
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();

            // Zamrożone dane produktu w momencie zakupu (pasuje 1:1 do OrderCalculator['items'])
            $table->string('product_name');
            $table->string('product_slug')->nullable();
            $table->uuid('variant_uuid')->nullable();
            $table->string('sku')->index();
            $table->json('attributes')->nullable();

            $table->unsignedInteger('unit_price');
            $table->unsignedInteger('regular_price')->nullable();
            $table->unsignedInteger('pack_price')->default(0);
            $table->unsignedSmallInteger('tax_rate')->default(VatRate::DEFAULT->value); // np. 2300 = 23.00%
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('subtotal');
            $table->unsignedInteger('discount_amount')->default(0);
            $table->unsignedInteger('total');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
