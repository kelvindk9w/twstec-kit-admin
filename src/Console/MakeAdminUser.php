<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Twstec\Kit\Auth\Contracts\AuthUser;
use Twstec\Kit\Auth\Exceptions\AccountProtectedException;
use Twstec\Kit\Auth\Support\UserModel;
use Twstec\Kit\Foundation\Audit\AuditScope;
use Twstec\Kit\Foundation\Audit\AuditTrail;

/**
 * Promoção/rebaixamento de super admin (acesso ao /admin).
 *
 * A flag is_admin NUNCA é mass-assignable nem editável por telas: a única
 * porta de entrada é este comando (trilha de quem rodou = log do SO/CI).
 *
 * CONTAS PROTEGIDAS ficam de fora (Twstec\Kit\Auth\Contracts\AccountProtection):
 * quando a extensão registrada protege `is_admin` de uma conta, o model recusa
 * a gravação. O comando não tenta contornar — traduz a recusa em erro de
 * console. (Hoje quem protege é a demonstração: promover o cliente demo a
 * admin, ou rebaixar o admin demo, entregaria o painel ao próximo visitante.)
 *
 * Promover também marca o e-mail como confirmado (quem promove é o operador;
 * ver docs/autenticacao.md, "Verificação de e-mail").
 *
 * TRILHA DE AUDITORIA (contexto `console`): promover grava
 * `user.admin_granted` e rebaixar `user.admin_revoked`, com o antes/depois da
 * flag; a recusa da conta protegida grava a mesma ação com `denied`. Sem usuário
 * da aplicação para ser o ator, a linha leva o comando e o usuário do sistema
 * operacional no lugar do User-Agent (AuditScope::console).
 *
 * Uso:
 *   php artisan user:make-admin email@exemplo.com          → promove
 *   php artisan user:make-admin email@exemplo.com --remove → rebaixa
 */
final class MakeAdminUser extends Command
{
    protected $signature = 'user:make-admin {email : E-mail do usuário} {--remove : Revoga o acesso de admin}';

    protected $description = 'Concede (ou revoga, com --remove) o acesso de super admin a um usuário';

    public function handle(AuditTrail $trail): int
    {
        $remove = (bool) $this->option('remove');
        $verb = $remove ? 'admin_revoked' : 'admin_granted';

        return $trail->within(
            AuditScope::console('user:make-admin'.($remove ? ' --remove' : ''), $verb),
            fn (): int => $this->apply($trail, $remove, $verb),
        );
    }

    private function apply(AuditTrail $trail, bool $remove, string $verb): int
    {
        /** @var (Model&AuthUser)|null $user */
        $user = UserModel::query()->where('email', $this->argument('email'))->first();

        if ($user === null) {
            // O e-mail digitado NÃO vai para a trilha (dado pessoal de
            // alguém que talvez nem tenha conta): só o fato da recusa.
            $trail->denied('user.'.$verb, null, __('admin.command.user_not_found'), subjectType: 'user');

            $this->error(__('admin.command.user_not_found'));

            return self::FAILURE;
        }

        try {
            // Promovido por quem opera o servidor: o e-mail passa a contar
            // como confirmado (mesma regra da conta criada pelo /admin), para
            // o novo admin não ficar preso no aviso de verificação do painel.
            // Rebaixar não mexe na verificação.
            $changes = ['is_admin' => ! $remove];

            if (! $remove && $user->email_verified_at === null) {
                $changes['email_verified_at'] = now();
            }

            // Mudança e linha da trilha na mesma transação (falha fechada).
            DB::transaction(fn (): bool => $user->forceFill($changes)->save());
        } catch (AccountProtectedException $exception) {
            $trail->denied('user.'.$verb, $user, __('admin.command.account_protected', ['email' => (string) $user->email]));

            $this->error(__('admin.command.account_protected', ['email' => (string) $user->email]));
            $this->line($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(__($remove ? 'admin.command.admin_removed' : 'admin.command.admin_granted', [
            'email' => $user->email,
        ]));

        return self::SUCCESS;
    }
}
