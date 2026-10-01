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
        Schema::create('order_product', function (Blueprint $table) {
            $table->foreignUuid('order_id')->constrained();
            $table->foreignId('product_id')->constrained();
            $table->primary(['order_id', 'product_id']);
        });
    }
};
