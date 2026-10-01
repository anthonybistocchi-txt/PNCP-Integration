<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ciclos_pca', function (Blueprint $table)
        {
            $table->id();
            $table->uuid('sync_id')->unique()->nullable();
            $table->string('cnpj_orgao', 14);
            $table->smallInteger('ano_pca');
            $table->integer('sequencial_pca')->nullable();
            $table->integer('total_anunciado')->default(0);
            $table->integer('total_paginas')->default(0);
            $table->integer('itens_processados')->default(0);
            $table->integer('paginas_completas')->default(0);
            $table->string('status', 16);
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ciclos_pca');
    }
};
