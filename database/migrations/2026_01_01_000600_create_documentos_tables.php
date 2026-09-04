<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('advogado_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('processo_id')->nullable()->constrained('processos')->cascadeOnDelete();
            $table->foreignId('cliente_id')->nullable()->constrained('clientes')->cascadeOnDelete();
            $table->string('nome');
            $table->string('tipo', 60)->nullable(); // rg, cpf, comprovante_residencia, cnis, laudo...
            $table->string('path');
            $table->string('mime', 120)->nullable();
            $table->unsignedBigInteger('tamanho')->default(0);
            $table->string('origem', 20)->default('upload'); // upload | camera
            $table->string('hash', 64)->nullable();
            $table->unsignedSmallInteger('paginas')->default(1);
            $table->timestamps();

            $table->index(['advogado_id', 'tipo']);
            $table->index(['processo_id']);
            $table->index(['cliente_id']);
        });

        // Log de acesso a documentos (secao 12 - seguranca)
        Schema::create('documento_acessos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('documento_id')->constrained('documentos')->cascadeOnDelete();
            $table->foreignId('advogado_id')->constrained('users')->cascadeOnDelete();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('em');

            $table->index(['documento_id', 'em']);
        });

        Schema::create('checklist_templates', function (Blueprint $table) {
            $table->id();
            $table->string('nome');
            $table->string('slug')->unique();
            $table->string('area', 40)->nullable();
            $table->json('itens'); // [{item, tipo_documento, obrigatorio}]
            $table->timestamps();
        });

        Schema::create('checklists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('processo_id')->constrained('processos')->cascadeOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('checklist_templates')->nullOnDelete();
            $table->string('item');
            $table->string('tipo_documento', 60)->nullable();
            $table->boolean('obrigatorio')->default(true);
            $table->foreignId('documento_id')->nullable()->constrained('documentos')->nullOnDelete();
            $table->unsignedSmallInteger('ordem')->default(0);
            $table->timestamps();

            $table->index(['processo_id', 'ordem']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checklists');
        Schema::dropIfExists('checklist_templates');
        Schema::dropIfExists('documento_acessos');
        Schema::dropIfExists('documentos');
    }
};
