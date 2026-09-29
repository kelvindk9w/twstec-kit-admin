<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// As colunas que o painel lê do usuário numa aplicação que o instala: a flag
// de acesso (`is_admin`) e a foto de perfil (`avatar_upload_id`, do
// twstec/kit-uploads). São do APLICATIVO (no starter, a migration de usuários
// dele cria as duas); a suíte do pacote as cria aqui, depois da tabela
// `uploads`.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_admin')->default(false);
            $table->foreignId('avatar_upload_id')->nullable()->constrained('uploads')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('avatar_upload_id');
            $table->dropColumn('is_admin');
        });
    }
};
