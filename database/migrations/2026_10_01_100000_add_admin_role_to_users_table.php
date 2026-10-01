<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// =============================================================================
// Papel do /admin (twstec/kit-admin, 2.0.0-beta.8).
//
// `admin_role`: o papel da pessoa no painel (chave de
// `admin.authorization.roles`). Entrar no /admin continua sendo `is_admin` +
// conta ativa; o papel diz o QUE ela pode fazer lá dentro. Nulo = nenhuma
// permissão (deny-by-default).
//
// MIGRAÇÃO SEM PERDA DE ACESSO: antes desta versão, todo `is_admin` podia
// tudo. Quem já é `is_admin` vira o papel de dono (`super_role`, padrão
// `owner`) aqui mesmo — nenhum administrador existente perde acesso no
// deploy. Contas criadas depois recebem o papel pelo `user:make-admin` ou
// pela ação "Alterar papel" do painel.
// =============================================================================
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'admin_role')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('admin_role', 64)->nullable()->index();
            });
        }

        if (Schema::hasColumn('users', 'is_admin')) {
            DB::table('users')
                ->where('is_admin', true)
                ->whereNull('admin_role')
                ->update(['admin_role' => (string) config('admin.authorization.super_role', 'owner')]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'admin_role')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropIndex(['admin_role']);
                $table->dropColumn('admin_role');
            });
        }
    }
};
