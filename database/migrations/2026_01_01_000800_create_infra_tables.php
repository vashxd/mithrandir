<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notificacoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('advogado_id')->constrained('users')->cascadeOnDelete();
            $table->string('tipo', 40); // publicacao_nova | prazo_d10 | prazo_d5 | ... | radar_cego
            $table->string('titulo');
            $table->text('corpo')->nullable();
            $table->string('url')->nullable();
            $table->json('payload')->nullable();
            $table->string('chave_dedup')->nullable();
            $table->timestamp('agendada_para');
            $table->timestamp('enviada_em')->nullable();
            $table->timestamp('lida_em')->nullable();
            $table->unsignedTinyInteger('tentativas')->default(0);
            $table->string('canal', 20)->default('push'); // push | email
            $table->timestamps();

            $table->index(['advogado_id', 'agendada_para']);
            $table->index(['advogado_id', 'lida_em']);
            $table->unique('chave_dedup');
        });

        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('advogado_id')->constrained('users')->cascadeOnDelete();
            $table->text('endpoint');
            $table->string('endpoint_hash', 64)->unique();
            $table->string('p256dh');
            $table->string('auth');
            $table->string('user_agent')->nullable();
            $table->unsignedTinyInteger('falhas')->default(0);
            $table->timestamp('ultima_entrega_em')->nullable();
            $table->timestamps();

            $table->index('advogado_id');
        });

        // Fila de escritas offline replicada do cliente (IndexedDB) - secao 10
        Schema::create('outbox', function (Blueprint $table) {
            $table->id();
            $table->foreignId('advogado_id')->constrained('users')->cascadeOnDelete();
            $table->uuid('client_id')->unique(); // idempotencia
            $table->string('entidade', 40);
            $table->string('operacao', 10); // create | update | delete
            $table->json('payload');
            $table->timestamp('criado_em');
            $table->timestamp('sincronizado_em')->nullable();
            $table->string('status', 20)->default('pendente'); // pendente|aplicado|conflito|erro
            $table->text('erro')->nullable();
            $table->timestamps();

            $table->index(['advogado_id', 'status']);
        });

        Schema::create('auditoria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('advogado_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('entidade', 60);
            $table->unsignedBigInteger('entidade_id')->nullable();
            $table->string('acao', 40);
            $table->json('antes')->nullable();
            $table->json('depois')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('em');

            $table->index(['entidade', 'entidade_id']);
            $table->index(['advogado_id', 'em']);
        });

        // Config editavel em runtime (chave DataJud etc. - secao 7.2)
        Schema::create('configuracoes', function (Blueprint $table) {
            $table->string('chave')->primary();
            $table->text('valor')->nullable();
            $table->string('descricao')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configuracoes');
        Schema::dropIfExists('auditoria');
        Schema::dropIfExists('outbox');
        Schema::dropIfExists('push_subscriptions');
        Schema::dropIfExists('notificacoes');
    }
};
