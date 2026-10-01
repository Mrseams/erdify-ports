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
        Schema::create('audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->string('event');
        });

        Schema::create('temp_imports', function (Blueprint $table) {
            $table->id();
        });
    }
};
