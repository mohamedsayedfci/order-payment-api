<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('customer_name', 120);
            $table->string('customer_email');
            $table->enum('status', ['pending', 'confirmed', 'cancelled'])->default('pending');
            $table->char('currency', 3);
            $table->unsignedBigInteger('total_minor');
            $table->timestamps();
            $table->index(['user_id', 'status', 'id']);
        });

        Schema::create('order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('product_name', 200);
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('unit_price_minor');
            $table->unsignedBigInteger('line_total_minor');
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->string('method', 50);
            $table->enum('status', ['pending', 'successful', 'failed'])->default('pending');
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->uuid('idempotency_key');
            $table->char('request_hash', 64);
            $table->string('gateway_reference')->nullable()->unique();
            $table->string('failure_code')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'idempotency_key']);
            $table->index(['order_id', 'status']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
