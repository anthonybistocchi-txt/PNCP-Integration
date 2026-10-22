<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cobertura_contratacao', function (Blueprint $table)
        {
            $table->unsignedSmallInteger('modalidade')->primary();
            // Primeiro data_final pelo qual esta modalidade passa a ser obrigação.
            $table->char('desde', 8);
            // Último data_final com ciclo fechado (empty ou finished completo), sem buraco antes.
            $table->char('coberto_ate', 8)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cobertura_contratacao');
    }
};
