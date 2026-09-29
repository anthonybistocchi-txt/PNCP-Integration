<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ciclos_contratacao', function (Blueprint $table) {
            $table->id();
            $table->uuid('sync_id')->nullable()->unique();
            $table->unsignedSmallInteger('modalidade');
            $table->char('data_inicial', 8);
            $table->char('data_final', 8);
            $table->unsignedInteger('total_anunciado')->default(0);
            $table->unsignedInteger('total_paginas')->default(0);
            $table->unsignedInteger('itens_processados')->default(0);
            $table->unsignedInteger('paginas_completas')->default(0);
            $table->string('status', 16);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'data_inicial', 'modalidade'], 'ciclos_contratacao_gaps_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ciclos_contratacao');
    }
};
