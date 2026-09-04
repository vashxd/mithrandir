<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('publicacoes', function (Blueprint $table) {
            $table->foreign('processo_id')->references('id')->on('processos')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('publicacoes', function (Blueprint $table) {
            $table->dropForeign(['processo_id']);
        });
    }
};
