<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Twstec\Kit\Accounts\Account\CurrentAccount;
use Twstec\Kit\Accounts\Account\Services\AccountService;
use Twstec\Kit\Accounts\Accounts;
use Twstec\Kit\Admin\Resources\AuditEvents\Pages\ListAuditEvents;
use Twstec\Kit\Admin\Resources\Uploads\Pages\ListUploads;
use Twstec\Kit\Admin\Tests\Fixtures\User;
use Twstec\Kit\Foundation\Audit\AuditTrail;
use Twstec\Kit\Foundation\Audit\Models\AuditEvent;
use Twstec\Kit\Uploads\Models\Upload;

// =============================================================================
// /admin, telas de UPLOADS e de AUDITORIA por conta (aplicação limpa): em
// modo sistema, como o resto do painel — Uploads mostram a conta de cada
// linha (e o tipo: da conta, foto pessoal ou órfão) e filtram por conta; a
// Auditoria filtra pela conta em que a ação aconteceu.
// =============================================================================

beforeEach(function (): void {
    Storage::fake('local');

    $this->ana = User::fixture(['email_verified_at' => now(), 'name' => 'Ana']);
    $this->bruno = User::fixture(['email_verified_at' => now(), 'name' => 'Bruno']);
    $this->contaDaAna = app(AccountService::class)->personalAccountOf($this->ana);
    $this->empresa = app(AccountService::class)->createAccount('Empresa do Bruno', $this->bruno);

    $grava = fn (array $dono, string $nome): Upload => Accounts::asSystem('teste: arranjo', function () use ($dono, $nome): Upload {
        $upload = new Upload;
        $upload->forceFill([
            'disk' => 'local',
            'path' => 'uploads/'.$nome,
            'original_name' => $nome,
            'mime' => 'application/pdf',
            'size' => 10,
            'sha256' => str_repeat('a', 64),
            ...$dono,
        ])->save();

        return $upload;
    });

    $this->daAna = $grava(['account_id' => $this->contaDaAna->id, 'created_by' => $this->ana->id], 'da-ana.pdf');
    $this->daEmpresa = $grava(['account_id' => $this->empresa->id, 'created_by' => $this->bruno->id], 'da-empresa.pdf');
    $this->foto = $grava(['personal' => true, 'created_by' => $this->ana->id], 'foto-da-ana.png');
});

it('UPLOADS: a tela (pela pilha real do painel) mostra todas as contas, com a conta e quem enviou', function (): void {
    $this->actingAs($this->admin())->get('/admin/uploads')
        ->assertOk()
        ->assertSee('da-ana.pdf')
        ->assertSee('da-empresa.pdf')
        ->assertSee('foto-da-ana.png')
        ->assertSee($this->contaDaAna->codigo_publico)
        ->assertSee($this->empresa->codigo_publico)
        ->assertSee($this->bruno->email)
        ->assertSee(__('admin.uploads.kind_personal'));

    expect(Accounts::inSystemMode())->toBeFalse();
});

it('UPLOADS: filtra por conta e pelo tipo de dono', function (): void {
    $this->actingAs($this->admin());
    app(CurrentAccount::class)->push(CurrentAccount::systemFrame('teste do /admin (Livewire::test)'));

    Livewire::test(ListUploads::class)
        ->assertCanSeeTableRecords([$this->daAna, $this->daEmpresa, $this->foto])
        ->filterTable('account', $this->empresa->id)
        ->assertCanSeeTableRecords([$this->daEmpresa])
        ->assertCanNotSeeTableRecords([$this->daAna, $this->foto]);

    Livewire::test(ListUploads::class)
        ->filterTable('kind', 'personal')
        ->assertCanSeeTableRecords([$this->foto])
        ->assertCanNotSeeTableRecords([$this->daAna, $this->daEmpresa]);
});

it('AUDITORIA: filtra pela conta em que a ação aconteceu', function (): void {
    $trilha = app(AuditTrail::class);
    $naEmpresa = $trilha->record('teste.na_empresa', null, [], 'teste', null, (string) $this->empresa->uuid);
    $naDaAna = $trilha->record('teste.na_conta_da_ana', null, [], 'teste', null, (string) $this->contaDaAna->uuid);
    $semConta = $trilha->record('teste.sem_conta');

    $this->actingAs($this->admin());
    app(CurrentAccount::class)->push(CurrentAccount::systemFrame('teste do /admin (Livewire::test)'));

    Livewire::test(ListAuditEvents::class)
        ->assertCanSeeTableRecords([$naEmpresa, $naDaAna, $semConta])
        ->filterTable('account', (string) $this->empresa->uuid)
        ->assertCanSeeTableRecords([$naEmpresa])
        ->assertCanNotSeeTableRecords([$naDaAna, $semConta]);

    // Valor adulterado (não é uuid): não derruba a consulta, não lista nada.
    Livewire::test(ListAuditEvents::class)
        ->filterTable('account', 'nao-e-uuid')
        ->assertCanNotSeeTableRecords([$naEmpresa, $naDaAna, $semConta]);

    expect(AuditEvent::query()->count())->toBeGreaterThanOrEqual(3);
});
