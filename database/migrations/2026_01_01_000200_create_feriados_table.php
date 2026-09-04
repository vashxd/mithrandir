<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feriados', function (Blueprint $table) {
            $table->id();
            $table->date('data');
            $table->string('descricao');
            // nacional | estadual | municipal | tribunal
            $table->string('abrangencia', 20);
            $table->string('uf', 2)->nullable();
            $table->string('municipio')->nullable();
            $table->string('tribunal_sigla', 20)->nullable();
            // true = suspensao de expediente forense (portaria), false = feriado civil
            $table->boolean('suspensao_expediente')->default(false);
            $table->string('fonte')->nullable();
            // Feriado cadastrado pelo proprio advogado (v2) fica com advogado_id preenchido.
            $table->foreignId('advogado_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['data', 'abrangencia']);
            $table->index(['uf', 'data']);
            $table->index(['tribunal_sigla', 'data']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feriados');
    }
};
