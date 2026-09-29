<?php

declare(strict_types=1);

use Twstec\Kit\Accounts\Account\Enums\AccountRole;
use Twstec\Kit\Accounts\Account\Services\AccountService;
use Twstec\Kit\Accounts\Accounts;
use Twstec\Kit\Accounts\ApiKeys\Models\ApiKey;
use Twstec\Kit\Accounts\ApiKeys\Services\ApiKeyService;
use Twstec\Kit\Accounts\Tenancy\Models\Project;
use Twstec\Kit\Admin\Resources\Users\Support\UserAdminGuard;
use Twstec\Kit\Admin\Tests\Fixtures\User;

// =============================================================================
// O /admin em MODO SISTEMA das contas, numa aplicação limpa (Testbench +
// Filament + o plugin, nada do starter): o painel vê projetos e chaves de
// todas as contas — com a conta de cada linha —, pela página e pelo endpoint
// de ações do Livewire; fora do painel, nada muda (o escopo segue exigindo
// conta).
// =============================================================================

/**
 * Projeto e chave na conta pessoal da pessoa.
 *
 * @return array{projeto: Project, chave: ApiKey}
 */
function dadosDaConta(User $pessoa, string $nome): array
{
    $conta = app(AccountService::class)->personalAccountOf($pessoa);

    return Accounts::actingAs($conta, fn (): array => [
        'projeto' => Project::createWithPublicCodeRetry(['name' => "Projeto {$nome}"]),
        'chave' => app(ApiKeyService::class)->create($pessoa, ['name' => "Chave {$nome}"])['api_key'],
    ], $pessoa);
}

it('as telas de projetos e chaves mostram TODAS as contas, com o código da conta de cada linha', function (): void {
    $ana = User::fixture(['email_verified_at' => now()]);
    $bruno = User::fixture(['email_verified_at' => now()]);
    dadosDaConta($ana, 'da Ana');
    dadosDaConta($bruno, 'do Bruno');

    $contaDaAna = app(AccountService::class)->personalAccountOf($ana);
    $contaDoBruno = app(AccountService::class)->personalAccountOf($bruno);

    $this->actingAs($this->admin())->get('/admin/projects')
        ->assertOk()
        ->assertSee('Projeto da Ana')
        ->assertSee('Projeto do Bruno')
        ->assertSee($contaDaAna->codigo_publico)
        ->assertSee($contaDoBruno->codigo_publico)
        ->assertSee($ana->email)
        ->assertSee($bruno->email);

    $this->get('/admin/api-keys')
        ->assertOk()
        ->assertSee('Chave da Ana')
        ->assertSee('Chave do Bruno');

    expect(Accounts::inSystemMode())->toBeFalse();
});

it('pelo endpoint do Livewire (ações, busca, widgets), o painel continua vendo todas as contas', function (): void {
    $ana = User::fixture(['email_verified_at' => now()]);
    $bruno = User::fixture(['email_verified_at' => now()]);
    dadosDaConta($ana, 'da Ana');
    dadosDaConta($bruno, 'do Bruno');

    $html = $this->actingAs($this->admin())->get('/admin/api-keys')->assertOk()->getContent();

    // Uma atualização do componente (a busca da tabela) chega pelo endpoint
    // do Livewire, fora da pilha do painel: o modo sistema precisa valer ali.
    $resposta = $this->livewireCall($this->snapshotFrom((string) $html, 'ListApiKeys'), '$refresh', ['tableSearch' => 'Bruno'])
        ->assertOk();

    expect((string) $resposta->json('components.0.effects.html'))->toContain('Chave do Bruno')
        ->not->toContain('Chave da Ana')
        ->and(Accounts::inSystemMode())->toBeFalse();
});

it('a exclusão da pessoa dona de conta com outros membros é recusada pela guarda do painel, com o motivo', function (): void {
    $dona = User::fixture();
    $membro = User::fixture();
    $empresa = app(AccountService::class)->createAccount('Empresa', $dona);
    app(AccountService::class)->addMember($empresa, $membro, AccountRole::Member);

    expect(UserAdminGuard::deleteDenial($dona, $this->admin()))->toContain($empresa->codigo_publico)
        ->and(UserAdminGuard::deleteDenial($membro, $this->admin()))->toBeNull();
});
