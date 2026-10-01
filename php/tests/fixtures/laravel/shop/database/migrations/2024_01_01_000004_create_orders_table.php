<?php

use App\Enums\ProductStatus;
use App\Models\Category;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->string('state')->default('pending');
            $table->integer('total_cents');
            $table->timestamps();
        });
    }
};
