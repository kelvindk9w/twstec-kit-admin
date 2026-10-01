<?php

declare(strict_types=1);

use Filament\Actions\Testing\TestAction;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Twstec\Kit\Accounts\Account\CurrentAccount;
use Twstec\Kit\Accounts\Account\Services\AccountService;
use Twstec\Kit\Accounts\Accounts;
use Twstec\Kit\Accounts\Deletion\DeletionImpediment;
use Twstec\Kit\Accounts\Deletion\DeletionImpediments;
use Twstec\Kit\Admin\Resources\Uploads\Pages\ListUploads;
use Twstec\Kit\Admin\Resources\Users\Pages\ListUsers;
use Twstec\Kit\Admin\Resources\Users\Support\UserAdminGuard;
use Twstec\Kit\Admin\Tests\Fixtures\User;
use Twstec\Kit\Foundation\Audit\Enums\AuditOutcome;
use Twstec\Kit\Foundation\Audit\Models\AuditEvent;
use Twstec\Kit\Uploads\Classification\UploadClassification;
use Twstec\Kit\Uploads\Confidential\ConfidentialAccess;
use Twstec\Kit\Uploads\Models\Upload;
use Twstec\Kit\Uploads\Retention\LegalHold;
use Twstec\Kit\Uploads\Services\SecureUploadService;

// =============================================================================
// /admin e os uploads CONFIDENCIAIS e a GUARDA LEGAL (aplicação limpa):
//
// - a tela mostra a classificação e o "guardar até" de cada linha;
// - abrir um confidencial é outra ação, com permissão própria: a URL só nasce
//   no clique (nunca ao desenhar a tabela), e a geração e a visualização vão
//   para a trilha com o contexto `admin` e o operador;
// - quem só tem `*.view` (auditor) vê a lista mas não abre o documento — 403
//   com a recusa na trilha;
// - pôr e tirar a guarda legal pelo painel ficam na trilha;
// - excluir usuário com impedimento declarado: recusa limpa, nada sai.
// =============================================================================

function pdfDoPainel(string $marca): string
{
    return "%PDF-1.4\n% {$marca}\n"
        ."1 0 obj\n<</Type/Catalog/Pages 2 0 R>>\nendobj\n"
        ."2 0 obj\n<</Type/Pages/Kids[3 0 R]/Count 1>>\nendobj\n"
        ."3 0 obj\n<</Type/Page/Parent 2 0 R/MediaBox[0 0 612 792]>>\nendobj\n"
        ."trailer\n<</Root 1 0 R>>\n%%EOF";
}

function enviaPeloPainel(string $marca, ?UploadClassification $classificacao = null): Upload
{
    $dona = User::fixture(['email_verified_at' => now()]);
    $arquivo = (string) tempnam(sys_get_temp_dir(), 'adm_');
    file_put_contents($arquivo, pdfDoPainel($marca));

    return Accounts::actingAs(
        app(AccountService::class)->personalAccountOf($dona),
        fn (): Upload => app(SecureUploadService::class)->handle(new UploadedFile($arquivo, $marca.'.pdf', null, null, true), classification: $classificacao),
        $dona,
    );
}

beforeEach(function (): void {
    Storage::fake('local');
    config(['uploads.confidential.key' => 'base64:'.base64_encode(random_bytes(32))]);

    $this->confidencial = enviaPeloPainel('DOC-CONFIDENCIAL', UploadClassification::Confidential);
    $this->comum = enviaPeloPainel('DOC-COMUM');
    $this->operador = $this->admin();
    $this->actingAs($this->operador);
    app(CurrentAccount::class)->push(CurrentAccount::systemFrame('teste do /admin (Livewire::test)'));
});

it('a tela mostra a classificação; desenhar a tabela NÃO gera URL do confidencial (nem linha na trilha)', function (): void {
    Livewire::test(ListUploads::class)
        ->assertCanSeeTableRecords([$this->confidencial, $this->comum])
        ->assertSee(__('admin.uploads.classification_confidential'))
        ->assertSee(__('admin.uploads.classification_private'))
        ->assertActionHidden(TestAction::make('open')->table($this->confidencial))
        ->assertActionVisible(TestAction::make('openConfidential')->table($this->confidencial))
        ->assertActionVisible(TestAction::make('open')->table($this->comum))
        ->assertActionHidden(TestAction::make('openConfidential')->table($this->comum))
        ->filterTable('classification', 'confidential')
        ->assertCanSeeTableRecords([$this->confidencial])
        ->assertCanNotSeeTableRecords([$this->comum]);

    expect(AuditEvent::query()->where('action', ConfidentialAccess::ISSUED)->exists())->toBeFalse();
});

it('ABRIR o confidencial pelo /admin: a URL nasce no clique, e a geração e a visualização ficam na trilha com o contexto `admin`', function (): void {
    $componente = Livewire::test(ListUploads::class)
        ->callAction(TestAction::make('openConfidential')->table($this->confidencial))
        ->assertRedirectContains('/uploads/confidential/');

    $url = (string) ($componente->effects['redirect'] ?? '');

    expect($url)->toContain('/uploads/confidential/'.$this->confidencial->uuid)
        ->and($url)->toContain('acc='.ConfidentialAccess::SYSTEM);

    $emitida = AuditEvent::query()->where('action', ConfidentialAccess::ISSUED)->sole();

    expect($emitida->context->value)->toBe('admin')
        ->and($emitida->actor_uuid)->toBe((string) $this->operador->uuid)
        ->and($emitida->subject_uuid)->toBe((string) $this->confidencial->uuid);

    app(CurrentAccount::class)->reset();

    $resposta = $this->get($url)->assertOk();

    expect($resposta->streamedContent())->toContain('DOC-CONFIDENCIAL');

    $vista = AuditEvent::query()->where('action', ConfidentialAccess::VIEWED)->sole();

    expect($vista->context->value)->toBe('admin')
        ->and($vista->actor_uuid)->toBe((string) $this->operador->uuid)
        ->and($vista->outcome)->toBe(AuditOutcome::Success);
});

it('quem só tem `*.view` (auditor) vê a lista mas NÃO abre o confidencial: 403 com a recusa na trilha', function (): void {
    $html = $this->get('/admin/uploads')->assertOk()->getContent();
    $snapshot = $this->snapshotFrom((string) $html, ListUploads::class);

    $this->operador->forceFill(['admin_role' => 'auditor'])->save();

    $this->livewireCall($snapshot, 'mountAction', [], ['openConfidential', [], ['table' => true, 'recordKey' => (string) $this->confidencial->getKey()]])
        ->assertForbidden();

    expect(AuditEvent::query()->where('outcome', AuditOutcome::Denied)->sole()->reason)->toContain('uploads.view_confidential')
        ->and(AuditEvent::query()->where('action', ConfidentialAccess::ISSUED)->exists())->toBeFalse();
});

it('GUARDA LEGAL pelo painel: pôr e tirar ficam na trilha (contexto `admin`), com o motivo', function (): void {
    $ate = now()->addYears(2)->toDateString();

    Livewire::test(ListUploads::class)
        ->callAction(TestAction::make('legalHold')->table($this->comum), ['retain_until' => $ate, 'reason' => 'Guarda contratual'])
        ->assertHasNoFormErrors();

    $registro = Accounts::asSystem('teste', fn () => Upload::query()->whereKey($this->comum->getKey())->firstOrFail());

    expect($registro->isUnderLegalHold())->toBeTrue()
        ->and($registro->retention_reason)->toBe('Guarda contratual');

    $posta = AuditEvent::query()->where('action', LegalHold::PLACED)->sole();

    expect($posta->context->value)->toBe('admin')
        ->and($posta->actor_uuid)->toBe((string) $this->operador->uuid);

    Livewire::test(ListUploads::class)
        ->callAction(TestAction::make('releaseLegalHold')->table($registro), ['reason' => 'Contrato rescindido e quitado'])
        ->assertHasNoFormErrors();

    expect(AuditEvent::query()->where('action', LegalHold::RELEASED)->sole()->changes['release_reason']['after'])->toBe('Contrato rescindido e quitado')
        // Uma linha por decisão: sem a captura automática duplicada.
        ->and(AuditEvent::query()->where('subject_uuid', $this->comum->uuid)->count())->toBe(2);
});

it('EXCLUIR USUÁRIO com impedimento declarado: o botão some e a pré-checagem dá o motivo', function (): void {
    $alvo = User::fixture(['email_verified_at' => now()]);

    app(DeletionImpediments::class)->register(fn ($pedido): array => $pedido->person?->getKey() === $alvo->getKey()
        ? [new DeletionImpediment('ledger_entries', 'Há lançamentos que a lei manda guardar.')]
        : []);

    Livewire::test(ListUsers::class)->assertActionHidden(TestAction::make('delete')->table($alvo));

    expect(UserAdminGuard::deleteDenial($alvo, $this->operador))->toBe('Há lançamentos que a lei manda guardar.')
        ->and(User::query()->whereKey($alvo->id)->exists())->toBeTrue();
});

it('EXCLUIR USUÁRIO referenciado por registro do aplicativo que NINGUÉM declarou: recusa limpa na hora, com a trilha — nunca o erro bruto do banco', function (): void {
    $alvo = User::fixture(['email_verified_at' => now()]);

    // A recusa do banco por chave estrangeira RESTRICT (as do SQLite da suíte
    // ficam desligadas dentro da transação do teste: o gatilho dá o mesmo
    // erro). A RESTRICT de verdade é coberta na suíte do starter.
    Schema::create('registros_do_app', fn (Blueprint $t) => $t->foreignId('user_id'));
    DB::table('registros_do_app')->insert(['user_id' => $alvo->id]);
    DB::unprepared("CREATE TRIGGER registros_restrict BEFORE DELETE ON users
        WHEN EXISTS (SELECT 1 FROM registros_do_app WHERE user_id = OLD.id)
        BEGIN SELECT RAISE(ABORT, 'FOREIGN KEY constraint failed'); END");

    Livewire::test(ListUsers::class)
        ->assertActionVisible(TestAction::make('delete')->table($alvo))
        ->callAction(TestAction::make('delete')->table($alvo))
        ->assertNotified(__('admin.users.action_denied'));

    expect(User::query()->whereKey($alvo->id)->exists())->toBeTrue()
        ->and(AuditEvent::query()->where('action', 'user.deleted')->where('outcome', AuditOutcome::Denied)->where('subject_uuid', $alvo->uuid)->sole()->reason)
        ->toBe(__('accounts.deletion.referenced'))
        ->and(AuditEvent::query()->where('action', 'user.deleted')->where('outcome', AuditOutcome::Success)->exists())->toBeFalse();
});
