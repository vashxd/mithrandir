<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oab_watches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('advogado_id')->constrained('users')->cascadeOnDelete();
            $table->string('termo');
            $table->string('tipo', 10); // oab | nome
            $table->string('uf', 2)->nullable();
            $table->boolean('ativo')->default(true);
            $table->timestamp('ultima_sync_em')->nullable();
            $table->unsignedSmallInteger('falhas_consecutivas')->default(0);
            $table->timestamps();

            $table->index(['advogado_id', 'ativo']);
        });

        Schema::create('sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('oab_watch_id')->constrained('oab_watches')->cascadeOnDelete();
            $table->timestamp('executado_em');
            $table->string('status', 20); // sucesso | falha
            $table->unsignedInteger('qtd_itens')->default(0);
            $table->unsignedInteger('qtd_novas')->default(0);
            $table->date('janela_inicio')->nullable();
            $table->date('janela_fim')->nullable();
            $table->unsignedInteger('duracao_ms')->nullable();
            $table->text('erro')->nullable();
            $table->timestamps();

            $table->index(['oab_watch_id', 'executado_em']);
        });

        Schema::create('publicacoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('advogado_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('oab_watch_id')->nullable()->constrained('oab_watches')->nullOnDelete();
            $table->string('hash', 64)->unique();
            $table->string('numero_comunicacao')->nullable();
            $table->string('tribunal', 40)->nullable();
            $table->string('orgao')->nullable();
            $table->string('numero_processo', 30)->nullable();
            $table->string('tipo_comunicacao')->nullable();
            $table->string('meio', 20)->nullable();
            $table->longText('teor')->nullable();
            $table->date('data_disponibilizacao');
            $table->json('destinatarios')->nullable();
            $table->json('payload_bruto')->nullable();
            // nova | prazo | ciencia | descartada
            $table->string('status_triagem', 20)->default('nova');
            $table->foreignId('processo_id')->nullable();
            $table->timestamp('triada_em')->nullable();
            $table->timestamp('arquivada_em')->nullable();
            $table->timestamps();

            $table->index(['advogado_id', 'status_triagem']);
            $table->index(['advogado_id', 'data_disponibilizacao']);
            $table->index('numero_processo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publicacoes');
        Schema::dropIfExists('sync_logs');
        Schema::dropIfExists('oab_watches');
    }
};
