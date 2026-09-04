<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clientes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('advogado_id')->constrained('users')->cascadeOnDelete();
            $table->string('nome');
            $table->string('documento', 20)->nullable();
            $table->date('nascimento')->nullable();
            $table->json('contatos')->nullable();  // [{tipo, valor, principal}]
            $table->json('endereco')->nullable();
            $table->string('origem')->nullable();  // indicacao, instagram, balcao
            $table->text('observacoes')->nullable();
            $table->timestamp('arquivado_em')->nullable();
            $table->timestamps();

            $table->index(['advogado_id', 'nome']);
            $table->index(['advogado_id', 'documento']);
        });

        Schema::create('processos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('advogado_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('cliente_id')->nullable()->constrained('clientes')->nullOnDelete();
            $table->string('numero_cnj', 25)->nullable();
            $table->string('titulo')->nullable();
            $table->string('tribunal', 40)->nullable();
            $table->string('vara')->nullable();
            $table->string('classe')->nullable();
            $table->string('assunto')->nullable();
            $table->string('fase', 40)->nullable();
            $table->string('area', 40)->nullable();
            $table->decimal('valor_causa', 14, 2)->nullable();
            $table->text('proxima_acao')->nullable();
            $table->boolean('segredo_justica')->default(false);
            $table->timestamp('datajud_sincronizado_em')->nullable();
            $table->timestamp('arquivado_em')->nullable();
            $table->timestamps();

            $table->index(['advogado_id', 'arquivado_em']);
            $table->index(['advogado_id', 'numero_cnj']);
        });

        Schema::create('partes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('processo_id')->constrained('processos')->cascadeOnDelete();
            $table->string('nome');
            $table->string('tipo', 20); // autor | reu | terceiro
            $table->string('documento', 20)->nullable();
            $table->string('advogado_adverso')->nullable();
            $table->timestamps();

            $table->index('processo_id');
        });

        Schema::create('movimentacoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('processo_id')->constrained('processos')->cascadeOnDelete();
            $table->date('data');
            $table->string('codigo', 30)->nullable();
            $table->text('descricao');
            $table->string('origem', 20)->default('manual'); // datajud | manual
            $table->string('hash', 64)->nullable();
            $table->timestamps();

            $table->index(['processo_id', 'data']);
            $table->unique(['processo_id', 'hash']);
        });

        Schema::create('atendimentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('advogado_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();
            $table->foreignId('processo_id')->nullable()->constrained('processos')->nullOnDelete();
            $table->date('data');
            $table->string('canal', 20)->default('presencial');
            $table->text('resumo');
            $table->timestamps();

            $table->index(['cliente_id', 'data']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atendimentos');
        Schema::dropIfExists('movimentacoes');
        Schema::dropIfExists('partes');
        Schema::dropIfExists('processos');
        Schema::dropIfExists('clientes');
    }
};
