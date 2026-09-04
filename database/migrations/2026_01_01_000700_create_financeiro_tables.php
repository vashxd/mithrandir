<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('honorarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('advogado_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('processo_id')->nullable()->constrained('processos')->cascadeOnDelete();
            $table->foreignId('cliente_id')->nullable()->constrained('clientes')->nullOnDelete();
            $table->string('tipo', 20); // fixo | parcelado | exito | misto
            $table->decimal('valor', 14, 2)->default(0);
            $table->decimal('percentual_exito', 5, 2)->nullable();
            $table->unsignedSmallInteger('qtd_parcelas')->default(1);
            $table->date('primeiro_vencimento')->nullable();
            $table->text('observacoes')->nullable();
            $table->timestamps();

            $table->index(['advogado_id', 'processo_id']);
        });

        Schema::create('parcelas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('advogado_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('honorario_id')->constrained('honorarios')->cascadeOnDelete();
            $table->unsignedSmallInteger('numero');
            $table->decimal('valor', 14, 2);
            $table->date('vencimento');
            $table->date('pago_em')->nullable();
            $table->decimal('valor_pago', 14, 2)->nullable();
            $table->string('forma_pagamento', 30)->nullable();
            $table->timestamp('cobranca_lembrada_em')->nullable();
            $table->timestamps();

            $table->index(['advogado_id', 'vencimento']);
            $table->index(['honorario_id', 'numero']);
        });

        Schema::create('despesas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('advogado_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('processo_id')->nullable()->constrained('processos')->cascadeOnDelete();
            $table->string('descricao');
            $table->string('categoria', 40)->nullable(); // custas, copias, deslocamento
            $table->decimal('valor', 14, 2);
            $table->date('data');
            $table->boolean('reembolsavel')->default(false);
            $table->date('reembolsada_em')->nullable();
            $table->timestamps();

            $table->index(['advogado_id', 'data']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('despesas');
        Schema::dropIfExists('parcelas');
        Schema::dropIfExists('honorarios');
    }
};
