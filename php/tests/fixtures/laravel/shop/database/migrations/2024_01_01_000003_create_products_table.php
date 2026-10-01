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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Category::class)->constrained();
            $table->string('name');
            $table->decimal('price', 10, 2);
            $table->string('status')->default(ProductStatus::Draft->value);
            $table->string('legacy_code')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }
};
