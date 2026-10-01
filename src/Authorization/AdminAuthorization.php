<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Authorization;

use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Pages\Page as ResourcePage;
use Filament\Resources\Resource;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Livewire\Component;
use Throwable;
use Twstec\Kit\Admin\Authorization\Contracts\GuardedByPermission;
use Twstec\Kit\Foundation\Audit\AuditTrail;

/**
 * A recusa por PERMISSÃO no servidor — o ponto em que esconder o botão deixa
 * de importar.
 *
 * Toda chamada Livewire de tela do painel passa pelo gancho do AdminAudit, que
 * pergunta aqui, ANTES de o método rodar, quais permissões a chamada exige:
 *
 * - qualquer chamada numa tela protegida exige a permissão da própria tela
 *   (listagem e detalhe: `<chave>.view`; edição: `<chave>.update`; criação:
 *   `<chave>.create`) — perder o papel no meio da sessão vale na próxima
 *   chamada, não só no próximo carregamento;
 * - montar ou executar uma Action (`mountAction`/`callMountedAction`, de
 *   tabela, cabeçalho ou modal, inclusive aninhada) exige `<chave>.<ação>`
 *   (ver abilityForAction — nome fora do mapa vira snake_case: deny by
 *   default para ação nova);
 * - "Salvar" da edição (`save`) exige `update`; "Criar" (`create`), `create`;
 *   coluna editável da tabela (`updateTableColumnState`), `update`.
 *
 * Faltou uma: a linha `denied` vai para a trilha (`<tipo>.<verbo>`, com o
 * registro quando dá para saber qual) e a resposta é 403 — nada do método
 * roda. Uma chamada FORJADA (o snapshot de uma tela que a pessoa abriu antes
 * de perder o papel, ou um `mountAction` de um botão que nem aparece) tem o
 * mesmo destino.
 *
 * As telas que entram aqui são as que implementam
 * Contracts\GuardedByPermission: todo resource do kit (BaseResource) — e,
 * portanto, todo resource que o aplicativo cria sobre ele — e as páginas que
 * declararem (Configurações). Tela que não implementa segue só a regra de
 * entrada do painel (Access\AdminAccess) e as policies que o aplicativo tiver.
 */
final class AdminAuthorization
{
    /**
     * Actions de TELA do Filament e do kit (filtros, colunas, agrupamento,
     * seleção, alternador tabela/cards): não mudam dado e não pedem permissão.
     *
     * @var list<string>
     */
    public const UI_ACTIONS = [
        'applyFilters',
        'applyTableColumnManager',
        'cancel',
        'deselectAll',
        'groupRecords',
        'openColumnManager',
        'openFilters',
        'removeAllFilters',
        'resetColumnManager',
        'resetFilters',
        'selectAll',
        'toggleViewMode',
    ];

    /**
     * Actions padrão do Filament → ação na permissão.
     *
     * @var array<string, string>
     */
    public const STANDARD_ABILITIES = [
        'view' => 'view',
        'edit' => 'update',
        'create' => 'create',
        'createAnother' => 'create',
        'delete' => 'delete',
        'forceDelete' => 'force_delete',
        'restore' => 'restore',
        'replicate' => 'replicate',
        'reorderRecords' => 'reorder',
        'export' => 'export',
        'import' => 'import',
    ];

    /**
     * Chave, no container, das chamadas do pedido Livewire em curso.
     */
    private const PENDING = 'twstec.kit-admin.pending-livewire-calls';

    /**
     * Guarda as chamadas de cada componente do pedido Livewire (pelo id do
     * componente, que vem no snapshot assinado) — para a conferência feita
     * ANTES de o componente hidratar, quando as chamadas ainda não rodaram.
     *
     * @param  array<int, mixed>  $payload
     */
    public static function rememberRequest(array $payload): void
    {
        $calls = [];

        foreach ($payload as $component) {
            if (! is_array($component) || ! is_string($component['snapshot'] ?? null)) {
                continue;
            }

            $snapshot = json_decode($component['snapshot'], true);
            $id = is_array($snapshot) ? ($snapshot['memo']['id'] ?? null) : null;

            if (! is_string($id)) {
                continue;
            }

            foreach ((array) ($component['calls'] ?? []) as $call) {
                if (is_array($call) && is_string($call['method'] ?? null)) {
                    $calls[$id][] = [
                        'method' => $call['method'],
                        'params' => is_array($call['params'] ?? null) ? array_values($call['params']) : [],
                    ];
                }
            }
        }

        app()->instance(self::PENDING, $calls);
    }

    /**
     * As chamadas pendentes de um componente neste pedido.
     *
     * @return list<array{method: string, params: array<int, mixed>}>
     */
    public static function pendingCalls(string $componentId): array
    {
        $pending = app()->bound(self::PENDING) ? app(self::PENDING) : [];

        return is_array($pending) ? ($pending[$componentId] ?? []) : [];
    }

    /**
     * A tela (ou o resource dela) que define as permissões deste componente.
     *
     * @return class-string<GuardedByPermission>|null
     */
    public static function guardOf(Component|string $component): ?string
    {
        $class = is_string($component) ? $component : $component::class;

        if (is_a($class, ResourcePage::class, true)) {
            $resource = $class::getResource();

            return is_a($resource, GuardedByPermission::class, true) ? $resource : null;
        }

        return is_a($class, GuardedByPermission::class, true) ? $class : null;
    }

    /**
     * A ação que ABRIR a tela exige.
     */
    public static function pageAbility(Component|string $component): string
    {
        return match (true) {
            is_a($component, EditRecord::class, true) => 'update',
            is_a($component, CreateRecord::class, true) => 'create',
            default => 'view',
        };
    }

    /**
     * Ação na permissão de uma Action desta tela (null = só de tela).
     *
     * @param  class-string<GuardedByPermission>  $guard
     */
    public static function abilityForAction(string $guard, string $name): ?string
    {
        $map = $guard::actionAbilities();

        if (array_key_exists($name, $map)) {
            return $map[$name];
        }

        if (in_array($name, self::UI_ACTIONS, true)) {
            return null;
        }

        return self::STANDARD_ABILITIES[$name] ?? Str::snake($name);
    }

    /**
     * Permissões que a chamada exige.
     *
     * @param  array<int, mixed>  $params
     * @return list<string>
     */
    public static function requiredFor(Component $component, string $method, array $params): array
    {
        $guard = self::guardOf($component);

        if ($guard === null) {
            return [];
        }

        $abilities = [self::pageAbility($component)];

        foreach (self::callAbilities($guard, $component, $method, $params) as $ability) {
            $abilities[] = $ability;
        }

        $key = $guard::permissionKey();

        return array_values(array_unique(array_map(
            static fn (string $ability): string => $key.'.'.$ability,
            $abilities,
        )));
    }

    /**
     * A primeira permissão que falta à pessoa para esta chamada (null = pode).
     *
     * @param  array<int, mixed>  $params
     */
    public static function missingFor(?Authenticatable $user, Component $component, string $method, array $params): ?string
    {
        foreach (self::requiredFor($component, $method, $params) as $permission) {
            if (! AdminPermissions::allows($user, $permission)) {
                return $permission;
            }
        }

        return null;
    }

    /**
     * Registra a recusa na trilha (`denied`) — a resposta 403 é de quem chama.
     *
     * @param  array<int, mixed>  $params
     */
    public static function recordDenial(Component $component, string $method, array $params, string $permission): void
    {
        $trail = app(AuditTrail::class);
        $guard = self::guardOf($component);
        $subject = self::subjectOf($component, $method, $params);
        $verb = $trail->current()?->verb ?? Str::snake($method);

        $type = match (true) {
            $subject !== null => AuditTrail::subjectType($subject),
            $guard !== null && is_a($guard, Resource::class, true) => AuditTrail::subjectType($guard::getModel()),
            $guard !== null => Str::singular($guard::permissionKey()),
            default => 'admin',
        };

        $trail->denied(
            "{$type}.{$verb}",
            $subject,
            __('admin.authorization.denied', ['permission' => $permission]),
            $subject === null ? $type : null,
        );
    }

    /**
     * @param  class-string<GuardedByPermission>  $guard
     * @param  array<int, mixed>  $params
     * @return list<string>
     */
    private static function callAbilities(string $guard, Component $component, string $method, array $params): array
    {
        $abilities = [];

        switch ($method) {
            case 'mountAction':
                // Nome que não é texto: o Filament recusaria de qualquer
                // jeito; aqui, uma permissão que ninguém tem.
                $abilities[] = is_string($params[0] ?? null)
                    ? self::abilityForAction($guard, $params[0])
                    : '__invalid__';

                break;

            case 'callMountedAction':
                foreach (self::mountedActionNames($component) as $name) {
                    $abilities[] = self::abilityForAction($guard, $name);
                }

                break;

            case 'save':
                $abilities[] = $component instanceof CreateRecord ? 'create' : 'update';

                break;

            case 'create':
                $abilities[] = 'create';

                break;

            case 'updateTableColumnState':
                $abilities[] = 'update';

                break;
        }

        return array_values(array_filter($abilities, static fn (?string $ability): bool => $ability !== null));
    }

    /**
     * @return list<string>
     */
    private static function mountedActionNames(Component $component): array
    {
        $mounted = property_exists($component, 'mountedActions') ? $component->mountedActions : [];

        if (! is_array($mounted)) {
            return ['__invalid__'];
        }

        $names = [];

        foreach ($mounted as $action) {
            $names[] = is_array($action) && is_string($action['name'] ?? null) ? $action['name'] : '__invalid__';
        }

        return $names;
    }

    /**
     * O registro alvo, quando dá para saber sem confiar demais no pedido: o
     * da página (edição/detalhe) ou o da linha da tabela.
     *
     * @param  array<int, mixed>  $params
     */
    private static function subjectOf(Component $component, string $method, array $params): ?Model
    {
        try {
            if (method_exists($component, 'getRecord')) {
                $record = $component->getRecord();

                if ($record instanceof Model) {
                    return $record;
                }
            }

            $key = self::tableRecordKey($component, $method, $params);
            $guard = self::guardOf($component);

            if ($key === null || $guard === null || ! is_a($guard, Resource::class, true)) {
                return null;
            }

            // Direto pelo model: a recusa pode acontecer antes de a tabela da
            // tela existir (conferência antes da hidratação).
            $model = $guard::getModel();

            return $model::query()->whereKey($key)->first();
        } catch (Throwable) {
            // Sem registro identificável: a recusa fica com o tipo.
            return null;
        }
    }

    /**
     * A chave da linha da tabela em que a Action foi (ou está) montada.
     *
     * @param  array<int, mixed>  $params
     */
    private static function tableRecordKey(Component $component, string $method, array $params): ?string
    {
        $context = [];

        if ($method === 'mountAction' && is_array($params[2] ?? null)) {
            $context = $params[2];
        } elseif ($method === 'callMountedAction' && property_exists($component, 'mountedActions') && is_array($component->mountedActions)) {
            $first = reset($component->mountedActions);
            $context = is_array($first) && is_array($first['context'] ?? null) ? $first['context'] : [];
        }

        $key = $context['recordKey'] ?? null;

        return is_string($key) || is_int($key) ? (string) $key : null;
    }
}
