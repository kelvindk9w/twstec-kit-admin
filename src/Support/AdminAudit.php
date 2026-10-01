<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Support;

use Filament\Notifications\Notification;
use Filament\Pages\SimplePage;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Livewire\Component;
use RuntimeException;
use Throwable;
use Twstec\Kit\Admin\Authorization\AdminAuthorization;
use Twstec\Kit\Admin\Support\Exceptions\RecordedDenial;
use Twstec\Kit\Foundation\Audit\AuditScope;
use Twstec\Kit\Foundation\Audit\AuditTrail;
use Twstec\Kit\Foundation\Audit\Enums\AuditContext;

use function Livewire\before;
use function Livewire\on;

/**
 * A trilha de auditoria de ações, ligada ao /admin num ponto SÓ.
 *
 * Toda escrita do painel passa por uma chamada Livewire — o submit de
 * "Criar"/"Salvar" (CreateRecord::create, EditRecord::save), o "Salvar" das
 * páginas próprias (Settings, Profile) e toda Action (tabela, cabeçalho,
 * modal: mountAction/callMountedAction). Em vez de cada resource lembrar de
 * registrar, register() se pendura no gancho `call` do Livewire: para
 * qualquer componente do painel, a chamada roda com um AuditScope aberto
 * (contexto `admin`, quem está logado, correlation_id da requisição, IP e
 * User-Agent). Dentro dele, AuditTrail grava cada created/updated/deleted de
 * model com o resumo redigido do que mudou.
 *
 * Consequência: um resource NOVO já nasce auditado — um CreateAction, um
 * DeleteAction, uma Action customizada que faz `->save()`, sem nenhuma linha
 * a mais. Os testes de arquitetura (tests/Architecture/AdminAuditTest do
 * pacote e tests/Feature/Architecture/AdminAuditTest do starter) garantem as duas portas que o gancho sozinho não fecha: escrita em massa
 * que não dispara evento de model e recusa sem registro.
 *
 * NOME DA AÇÃO: `<tipo>.<verbo>`. O verbo sai do nome da Action em curso
 * pelo mapa VERBS (`block` → `blocked`, `revoke` → `revoked`...); nome fora
 * do mapa vira snake_case (`archiveOld` → `archive_old`), estável sem
 * cadastro. CRUD usa o próprio evento (`product.created`).
 *
 * RECUSAS: guarda de servidor que recusa uma ação chama denied() — que
 * registra `outcome = denied` com o motivo E mostra a notificação ao
 * operador. É uma chamada só de propósito: não existe recusar sem registrar.
 *
 * FORA DO ESCOPO: páginas de autenticação (SimplePage — login, código do
 * segundo fator): ninguém está logado ainda e nada ali é ação de admin.
 */
final class AdminAudit
{
    /**
     * Nome da Action (ou do método da página) → verbo estável na trilha.
     *
     * @var array<string, string>
     */
    public const VERBS = [
        'create' => 'created',
        'save' => 'updated',
        'edit' => 'updated',
        'delete' => 'deleted',
        'forceDelete' => 'force_deleted',
        'restore' => 'restored',
        'replicate' => 'replicated',
        'block' => 'blocked',
        'unblock' => 'unblocked',
        'revoke' => 'revoked',
        'rotate' => 'rotated',
        'markEmailVerified' => 'email_marked_verified',
        'assignRole' => 'role_changed',
        'confirmAssignRole' => 'role_changed',
        'approve' => 'approved',
        'confirmApprove' => 'approved',
        'reject' => 'rejected',
        'execute' => 'executed',
    ];

    /**
     * Pendura o escopo de auditoria em toda chamada Livewire de componente do
     * painel. Chamado uma vez por aplicação, pelo PRÓPRIO pacote (boot do
     * AdminServiceProvider) — não depende de o aplicativo lembrar de ligar.
     */
    public static function register(): void
    {
        // PERMISSÃO POR PAPEL, no servidor (Authorization\AdminAuthorization),
        // em dois pontos:
        //
        // 1. ANTES de o componente hidratar — antes até do `hydrate()` do
        //    próprio Filament, que recusaria a tela de edição/detalhe com um
        //    403 sem deixar rastro: a tela e as chamadas que vieram no pedido
        //    (lidas do corpo da requisição do Livewire) são conferidas aqui;
        // 2. em cada chamada, depois de aplicadas as atualizações de
        //    propriedade (um `mountedActions` mexido pelo cliente é visto
        //    aqui) — e no caminho que não passa pelo endpoint (testes).
        //
        // Faltou permissão: linha `denied` na trilha e 403; nada roda.
        on('request', function (array $payload): void {
            AdminAuthorization::rememberRequest($payload);
        });

        before('hydrate', function (mixed $component): void {
            if (! $component instanceof Component || ! self::covers($component) || ! auth()->check()) {
                return;
            }

            $calls = AdminAuthorization::pendingCalls($component->getId());

            foreach ($calls === [] ? [['method' => '', 'params' => []]] : $calls as $call) {
                self::authorizeCall($component, $call['method'], $call['params']);
            }
        });

        on('call', function (Component $component, string $method, array $params): ?callable {
            if (! self::covers($component) || ! auth()->check()) {
                return null;
            }

            self::authorizeCall($component, $method, $params);

            $trail = app(AuditTrail::class);

            $previous = $trail->begin(AuditScope::fromRequest(
                AuditContext::Admin,
                self::verbFor($component, $method, $params),
            ));

            return function (mixed $return = null) use ($trail, $previous): mixed {
                $trail->restore($previous);

                return $return;
            };
        });

        // Exceção no meio da chamada: o finalizador acima não roda. O escopo
        // não pode ficar aberto para o resto do processo.
        on('exception', function (mixed $component, Throwable $exception): void {
            if ($component instanceof Component && self::covers($component)) {
                app(AuditTrail::class)->restore(null);
            }
        });
    }

    /**
     * Recusa (403 + `denied` na trilha, no escopo da própria chamada) a
     * chamada que pede o que o papel não dá.
     *
     * @param  array<int, mixed>  $params
     */
    private static function authorizeCall(Component $component, string $method, array $params): void
    {
        $missing = AdminAuthorization::missingFor(auth()->user(), $component, $method, $params);

        if ($missing === null) {
            return;
        }

        $trail = app(AuditTrail::class);

        $previous = $trail->begin(AuditScope::fromRequest(
            AuditContext::Admin,
            self::verbFor($component, $method === '' ? 'view' : $method, $params),
        ));

        try {
            AdminAuthorization::recordDenial($component, $method, $params, $missing);
        } finally {
            $trail->restore($previous);
        }

        abort(403, __('admin.authorization.denied', ['permission' => $missing]));
    }

    /**
     * O componente pertence ao /admin (e não à autenticação dele)?
     *
     * - as telas deste pacote (Twstec\Kit\Admin\);
     * - as do próprio Filament (Filament\);
     * - as telas do painel escritas pelo APLICATIVO, no lugar em que o
     *   Filament as gera (`<namespace do app>\Filament\` — no starter,
     *   App\Filament\): um resource que o projeto acrescenta ao próprio painel
     *   nasce auditado como os do pacote;
     * - as das extensões que registraram o seu namespace em
     *   `audit.admin_extension_namespaces` (a demonstração do kit registra o
     *   dela).
     *
     * @param  Component|class-string  $component
     */
    public static function covers(Component|string $component): bool
    {
        $class = is_string($component) ? $component : $component::class;

        if (is_a($class, SimplePage::class, true)) {
            return false;
        }

        $namespaces = [
            'Twstec\\Kit\\Admin\\',
            'Filament\\',
            ...self::applicationNamespaces(),
            ...(array) config('audit.admin_extension_namespaces', []),
        ];

        foreach ($namespaces as $namespace) {
            if (is_string($namespace) && $namespace !== '' && str_starts_with($class, $namespace)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Onde o aplicativo escreve as telas do próprio painel: o namespace dele
     * (o do composer.json, que o Laravel resolve) + `Filament\`.
     *
     * @return list<string>
     */
    private static function applicationNamespaces(): array
    {
        try {
            return [app()->getNamespace().'Filament\\'];
        } catch (RuntimeException) {
            // Aplicação sem namespace detectável (ex.: esqueleto de teste):
            // só as telas do pacote, do Filament e das extensões.
            return [];
        }
    }

    /**
     * Verbo estável de uma Action/método.
     */
    public static function verb(string $name): string
    {
        return self::VERBS[$name] ?? Str::snake($name);
    }

    /**
     * Registra uma TENTATIVA RECUSADA e avisa o operador — numa chamada só.
     *
     * - `$reason`: o motivo que o operador lê (vai também para a trilha,
     *   passando pelo Redactor);
     * - `$subject`: o registro alvo da tentativa;
     * - `$verb`: o verbo, quando não é o da Action em curso;
     * - `$title`: título da notificação quando o motivo vai no corpo;
     * - `$subjectType`: o tipo do alvo quando ele ainda não existe (ex.: a
     *   criação de uma conta recusada antes do INSERT → `user.created`).
     */
    public static function denied(string $reason, ?Model $subject = null, ?string $verb = null, ?string $title = null, ?string $subjectType = null): void
    {
        $trail = app(AuditTrail::class);

        $verb ??= $trail->current()?->verb ?? 'updated';
        $type = $subject !== null ? AuditTrail::subjectType($subject) : ($subjectType ?? 'admin');

        $trail->denied("{$type}.{$verb}", $subject, $reason, $subject === null ? $subjectType : null);

        $notification = Notification::make()->danger();

        $title === null
            ? $notification->title($reason)
            : $notification->title($title)->body($reason);

        $notification->send();
    }

    /**
     * Avisa o operador de uma recusa que JÁ ESTÁ na trilha — registrada pelo
     * próprio serviço que recusou (Approvals\ApprovalService,
     * Authorization\AdminRoles), que é a barreira de servidor também fora do
     * painel. Só aceita a exceção desses serviços: não serve para recusar sem
     * registrar.
     */
    public static function notifyRecorded(RecordedDenial $denial, ?string $title = null): void
    {
        $notification = Notification::make()->danger();

        $title === null
            ? $notification->title($denial->getMessage())
            : $notification->title($title)->body($denial->getMessage());

        $notification->send();
    }

    /**
     * Roda um serviço do painel (papéis, aprovações) dentro de um escopo de
     * auditoria — o da chamada Livewire em curso, renomeado para `$verb`, ou
     * um novo quando o serviço é chamado de fora do painel (comando, job,
     * teste): a trilha não depende de quem chamou.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function within(string $verb, callable $callback, ?Authenticatable $actor = null): mixed
    {
        $trail = app(AuditTrail::class);

        if ($trail->current() !== null) {
            $trail->describeAs($verb);

            return $callback();
        }

        if (auth()->check() && ($actor === null || auth()->id() === $actor->getAuthIdentifier())) {
            return $trail->within(AuditScope::fromRequest(AuditContext::Admin, $verb), $callback);
        }

        // Fora de uma requisição do painel (comando, job): o ator é quem o
        // serviço recebeu, e o "cliente" é o console.
        $console = AuditScope::console('admin', $verb);

        return $trail->within(new AuditScope(
            context: $console->context,
            actorUuid: $actor instanceof Model && is_string($actor->getAttribute('uuid')) ? $actor->getAttribute('uuid') : null,
            actorIsAdmin: $actor instanceof Model ? (bool) $actor->getAttribute('is_admin') : null,
            correlationId: $console->correlationId,
            ip: null,
            userAgent: $console->userAgent,
            verb: $verb,
        ), $callback);
    }

    /**
     * Dá nome à ação em curso quando ele depende do estado (ex.: a mesma
     * Action liga e desliga o segundo fator).
     */
    public static function describeAs(string $verb): void
    {
        app(AuditTrail::class)->describeAs($verb);
    }

    /**
     * @param  array<int, mixed>  $params
     */
    private static function verbFor(Component $component, string $method, array $params): string
    {
        $name = match ($method) {
            'mountAction' => is_string($params[0] ?? null) ? $params[0] : $method,
            'callMountedAction' => self::lastMountedActionName($component) ?? $method,
            default => $method,
        };

        return self::verb($name);
    }

    private static function lastMountedActionName(Component $component): ?string
    {
        $mounted = property_exists($component, 'mountedActions') ? $component->mountedActions : [];

        if (! is_array($mounted) || $mounted === []) {
            return null;
        }

        $last = end($mounted);

        return is_array($last) && is_string($last['name'] ?? null) ? $last['name'] : null;
    }
}
