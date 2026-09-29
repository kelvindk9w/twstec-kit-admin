<?php

declare(strict_types=1);

use Twstec\Kit\Accounts\Account\Enums\AccountRole;
use Twstec\Kit\Accounts\Account\Services\AccountService;
use Twstec\Kit\Accounts\Accounts;
use Twstec\Kit\Accounts\ApiKeys\Services\ApiKeyService;
use Twstec\Kit\Accounts\Tenancy\Models\Project;
use Twstec\Kit\Admin\Resources\Accounts\AccountResource;
use Twstec\Kit\Admin\Resources\Accounts\Pages\ListAccounts;
use Twstec\Kit\Admin\Resources\Accounts\Pages\ViewAccount;
use Twstec\Kit\Admin\Support\AdminAudit;
use Twstec\Kit\Admin\Tests\Fixtures\User;
use Twstec\Kit\Foundation\Audit\Models\AuditEvent;

// =============================================================================
// O resource "Contas" do /admin, numa aplicação limpa: SOMENTE LEITURA, em
// modo sistema (todas as contas), com o detalhe mostrando membros e papéis e
// os projetos e chaves da conta — nunca a secreta da chave. Sem criar,
// editar nem excluir; coberto pelo escopo de auditoria como os demais.
// =============================================================================

it('lista todas as contas (pessoais e de empresa), com dono, tipo e contagens', function (): void {
    $ana = User::fixture(['email_verified_at' => now(), 'name' => 'Ana Dona']);
    $bruno = User::fixture(['email_verified_at' => now(), 'name' => 'Bruno Membro']);
    $empresa = app(AccountService::class)->createAccount('Empresa Listada', $ana);
    app(AccountService::class)->addMember($empresa, $bruno, AccountRole::Member);

    $this->actingAs($this->admin())->get('/admin/accounts')
        ->assertOk()
        ->assertSee('Empresa Listada')
        ->assertSee($empresa->codigo_publico)
        ->assertSee(app(AccountService::class)->personalAccountOf($bruno)->codigo_publico)
        ->assertSee($ana->email)
        ->assertSee(__('admin.accounts.type_company'))
        ->assertSee(__('admin.accounts.type_personal'));

    expect(Accounts::inSystemMode())->toBeFalse();
});

it('o detalhe mostra membros com papel, projetos e chaves da conta — sem a secreta', function (): void {
    $ana = User::fixture(['email_verified_at' => now(), 'name' => 'Ana Dona']);
    $bruno = User::fixture(['email_verified_at' => now(), 'name' => 'Bruno Admin']);
    $empresa = app(AccountService::class)->createAccount('Empresa Detalhada', $ana);
    app(AccountService::class)->addMember($empresa, $bruno, AccountRole::Admin);

    $chave = Accounts::actingAs($empresa, function () use ($bruno): array {
        Project::createWithPublicCodeRetry(['name' => 'Projeto Detalhado']);

        return app(ApiKeyService::class)->create($bruno, ['name' => 'Chave Detalhada']);
    }, $bruno);

    $html = $this->actingAs($this->admin())->get("/admin/accounts/{$empresa->uuid}")
        ->assertOk()
        ->assertSee('Ana Dona')
        ->assertSee('Bruno Admin')
        ->assertSee(__('accounts.roles.owner'))
        ->assertSee(__('accounts.roles.admin'))
        ->assertSee('Projeto Detalhado')
        ->assertSee('Chave Detalhada')
        ->assertSee($chave['api_key']->public_key)
        ->getContent();

    expect($html)->not->toContain($chave['secret_key'])
        ->and($html)->not->toContain((string) Accounts::asSystem('teste', fn () => $chave['api_key']->fresh()->secret_hash));
});

it('é somente leitura: sem criar, editar nem excluir — e coberto pela auditoria do painel', function (): void {
    $ana = User::fixture(['email_verified_at' => now()]);
    $conta = app(AccountService::class)->createAccount('Só Leitura', $ana);

    expect(AccountResource::canCreate())->toBeFalse()
        ->and(AccountResource::canEdit($conta))->toBeFalse()
        ->and(AccountResource::canDelete($conta))->toBeFalse()
        ->and(array_keys(AccountResource::getPages()))->toBe(['index', 'view'])
        ->and(AdminAudit::covers(ListAccounts::class))->toBeTrue()
        ->and(AdminAudit::covers(ViewAccount::class))->toBeTrue();

    $this->actingAs($this->admin())->get("/admin/accounts/{$conta->uuid}/edit")->assertNotFound();
    $this->get('/admin/accounts/create')->assertNotFound();

    // Ler não escreve nada na trilha.
    expect(AuditEvent::query()->count())->toBe(0);
});

it('quem não é admin não entra', function (): void {
    $pessoa = User::fixture(['email_verified_at' => now()]);

    $this->actingAs($pessoa)->get('/admin/accounts')->assertForbidden();
});
