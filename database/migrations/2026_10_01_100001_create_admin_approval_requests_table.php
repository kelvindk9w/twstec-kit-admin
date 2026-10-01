<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// =============================================================================
// Pedidos de aprovação em dois passos do /admin (twstec/kit-admin,
// 2.0.0-beta.8) — ver Twstec\Kit\Admin\Approvals\ApprovalService.
//
// - `action`: a chave da ApprovableAction (ex.: `users.delete`);
// - `subject_type`/`subject_uuid`: o registro alvo (o id interno nunca);
// - `payload`: os dados para executar, CIFRADOS em repouso (cast
//   `encrypted:array`) — a trilha só registra que existem;
// - `summary`: o antes/depois já REDIGIDO (o que o aprovador lê);
// - `fingerprint`: o retrato (SHA-256) do estado do alvo no pedido — se
//   mudou até a aprovação, o pedido não executa;
// - quem pediu/decidiu/executou por UUID (sem FK: a pessoa pode ser excluída
//   depois, e o histórico do pedido continua legível, como na trilha);
// - `expires_at`: validade do pedido; `executable_after` (modo de um
//   operador): a espera mínima entre aprovar e executar.
// =============================================================================
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_approval_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('action', 120)->index();
            $table->string('mode', 20);
            $table->string('status', 20)->index();
            $table->string('subject_type', 64)->nullable();
            $table->uuid('subject_uuid')->nullable()->index();
            $table->text('payload')->nullable();
            $table->json('summary')->nullable();
            $table->string('fingerprint', 64);
            $table->text('reason')->nullable();
            $table->uuid('requested_by_uuid')->index();
            $table->timestamp('expires_at');
            $table->uuid('decided_by_uuid')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->timestamp('executable_after')->nullable();
            $table->uuid('executed_by_uuid')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_approval_requests');
    }
};
