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
        Schema::create('gold_transactions', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['buy', 'sell'])->default('buy')->comment('Transaction type: buy or sell');
            $table->decimal('grams', 10, 4)->comment('Amount of gold in grams');
            $table->decimal('price_per_gram', 10, 2)->comment('Price per gram at time of transaction');
            $table->decimal('total_price', 12, 2)->comment('Total transaction price');
            $table->timestamp('transacted_at')->comment('When the transaction occurred');
            $table->timestamps();

            // Indexes for faster queries
            $table->index('type');
            $table->index('transacted_at');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gold_transactions');
    }
};