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
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone')->nullable()->after('email');
            $table->renameColumn('name', 'full_name');
        });

        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('legacy_code'));

        Schema::table('orders', function (Blueprint $table) {
            $table->bigInteger('total_cents')->default(0)->change();
        });

        Schema::table('audits', function (Blueprint $table) {
            $table->dropForeign('audits_user_id_foreign');
        });
        Schema::rename('audits', 'audit_logs');

        Schema::dropIfExists('temp_imports');
    }
};
