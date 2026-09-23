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
        Schema::create('pncp_orgaos', function (Blueprint $table) {
            $table->id();
            $table->string('cnpj', 18)->unique();
            $table->string('razao_social', 255)->nullable();
            $table->char('poder_id', 1)->nullable();
            $table->char('esfera_id', 1)->nullable();
            $table->boolean('ativo')->default(true)->index();
            $table->string('importado_de', 50)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pncp_orgaos');
    }
};
