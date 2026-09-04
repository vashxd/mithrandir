<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Catalogo RF-2.10
        Schema::create('tipos_prazo', function (Blueprint $table) {
            $table->id();
            $table->string('nome');
            $table->string('slug')->unique();
            $table->unsignedSmallInteger('dias');
            $table->boolean('em_dias_uteis')->default(true);
            $table->string('base_legal')->nullable();
            $table->string('area', 40)->nullable();
            $table->boolean('ativo')->default(true);
            $table->timestamps();
        });

        Schema::create('prazos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('advogado_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('processo_id')->nullable()->constrained('processos')->cascadeOnDelete();
            $table->foreignId('publicacao_id')->nullable()->constrained('publicacoes')->nullOnDelete();
            $table->foreignId('tipo_prazo_id')->nullable()->constrained('tipos_prazo')->nullOnDelete();

            $table->string('tipo');
            $table->unsignedSmallInteger('dias');
            $table->boolean('em_dias_uteis')->default(true);
            $table->unsignedTinyInteger('multiplicador')->default(1);
            $table->string('multiplicador_motivo')->nullable();

            $table->date('data_disponibilizacao');
            $table->date('data_publicacao');
            $table->date('data_inicio');
            $table->date('data_fatal');
            $table->date('data_alvo');
            $table->unsignedTinyInteger('buffer_dias')->default(3);

            $table->boolean('ajustado_manualmente')->default(false);
            $table->date('data_fatal_calculada')->nullable();
            $table->text('justificativa')->nullable();

            $table->string('status', 20)->default('aberto');
            $table->timestamp('cumprido_em')->nullable();
            $table->text('observacoes')->nullable();

            // Cadeia de origem completa (RF-2.7) serializada no momento do calculo.
            $table->json('cadeia_origem')->nullable();
            $table->boolean('precisa_revisao')->default(false);
            $table->string('revisao_motivo')->nullable();

            $table->timestamps();

            $table->index(['advogado_id', 'data_alvo']);
            $table->index(['advogado_id', 'status']);
            $table->index(['processo_id', 'status']);
        });

        Schema::create('eventos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('advogado_id')->constrained('users')->cascadeOnDelete();
            $table->string('tipo', 20); // prazo | audiencia | compromisso | tarefa
            $table->string('eventable_type')->nullable();
            $table->unsignedBigInteger('eventable_id')->nullable();
            $table->foreignId('processo_id')->nullable()->constrained('processos')->cascadeOnDelete();
            $table->foreignId('cliente_id')->nullable()->constrained('clientes')->nullOnDelete();
            $table->string('titulo');
            $table->text('descricao')->nullable();
            $table->dateTime('inicio');
            $table->dateTime('fim')->nullable();
            $table->boolean('dia_inteiro')->default(false);
            $table->string('local')->nullable();
            $table->string('link')->nullable();
            $table->unsignedSmallInteger('lembrete_deslocamento_min')->nullable();
            $table->boolean('concluido')->default(false);
            $table->timestamps();

            $table->index(['advogado_id', 'inicio']);
            $table->index(['eventable_type', 'eventable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eventos');
        Schema::dropIfExists('prazos');
        Schema::dropIfExists('tipos_prazo');
    }
};
