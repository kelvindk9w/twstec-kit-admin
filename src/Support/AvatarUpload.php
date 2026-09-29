<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Support;

use Filament\Forms\Components\FileUpload;
use Illuminate\Database\Eloquent\Model;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Twstec\Kit\Accounts\Account\Services\AccountService;
use Twstec\Kit\Auth\Contracts\AuthUser;
use Twstec\Kit\Foundation\Identifiers\UuidColumn;
use Twstec\Kit\Foundation\Kit;
use Twstec\Kit\Uploads\Exceptions\UploadRejectedException;
use Twstec\Kit\Uploads\Models\Upload;
use Twstec\Kit\Uploads\Rules\SafeFile;
use Twstec\Kit\Uploads\Services\SecureUploadService;

/**
 * Campo de FOTO DE PERFIL do super admin, em um lugar só (cadastro de
 * usuário e perfil do próprio admin usam este mesmo campo).
 *
 * O ponto que não podia ser negociado: o Filament, de fábrica, grava o
 * arquivo direto no disco. Isso pularia a FUNÇÃO GLOBAL DE UPLOAD do kit
 * (SecureUploadService) e, com ela, a validação por magic bytes,
 * o re-encode GD, o nome derivado do MIME real e o registro em `uploads`.
 * Ou seja: o /admin viraria a única porta do sistema por onde um arquivo
 * entra sem passar pela lei.
 *
 * Por isso `saveUploadedFileUsing()` delega ao service e devolve o UUID do
 * Upload criado — é esse uuid que as páginas transformam em
 * `avatar_upload_id`. E `SafeFile` roda antes, na validação, para que um
 * .txt renomeado para .png apareça como erro embaixo do campo, e não como
 * erro de servidor depois do "Salvar".
 *
 * A foto é da PESSOA, não de uma conta: o arquivo enviado aqui vira um upload
 * PESSOAL (SecureUploadService::handlePersonal — sem conta, com `created_by`
 * = o operador que enviou), lido só pela foto de perfil (HasAvatar).
 *
 * DONO DO UPLOAD: o valor do campo chega do navegador (estado do Livewire) e
 * pode ser trocado por qualquer uuid. Só vira foto de uma pessoa o upload que
 * é DELA (foto pessoal que ela mesma enviou, upload da conta pessoal dela, ou
 * já vinculado como a foto atual) ou o que acabou de ser enviado NESTE
 * formulário, nesta requisição (ver FreshAvatarUploads). Qualquer outro é recusado: as páginas
 * que gravam a foto (criar/editar usuário e o perfil do admin) perguntam
 * denialFor() ANTES de gravar qualquer coisa e registram a recusa na trilha
 * (AdminAudit::denied). applyTo() é a gravação em si e não repete a pergunta:
 * quem a chamar fora dessas páginas pergunta denialFor() antes.
 *
 * A foto é servida por URL ASSINADA e de curta duração: o campo declara
 * `visibility('private')`, que é o que faz o Filament pedir `temporaryUrl()`
 * ao disco em vez de montar uma URL pública.
 *
 * SÓ COM O twstec/kit-uploads: sem o pacote não existe foto de perfil — as
 * telas não mostram o campo (available()) e stateFor/denialFor/applyTo não
 * fazem nada; o avatar do painel fica nas iniciais (InitialsAvatarProvider).
 */
final class AvatarUpload
{
    public const DIRECTORY = 'avatars';

    /**
     * A foto de perfil existe nesta aplicação (twstec/kit-uploads instalado)?
     * As telas só chamam field() quando sim.
     */
    public static function available(): bool
    {
        return Kit::has('uploads');
    }

    /**
     * O campo do formulário. `$name` não é coluna do model: o valor é lido
     * e gravado pelas páginas (ver applyTo()).
     */
    public static function field(string $name = 'avatar'): FileUpload
    {
        $maxKb = (int) data_get(config('uploads.types'), 'image.max_kb', 5120);

        return FileUpload::make($name)
            ->label(__('admin.users.avatar'))
            ->helperText(__('admin.users.avatar_hint', ['max' => $maxKb]))
            ->avatar()
            ->image()
            ->imagePreviewHeight('96')
            ->disk((string) config('uploads.disk', 'local'))
            ->directory(self::DIRECTORY)
            // Sem isto o Filament monta URL pública: a política do kit é
            // documento nunca em bucket público.
            ->visibility('private')
            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
            ->maxSize($maxKb)
            ->rules([new SafeFile(['image'])])
            // A gravação é do service, não do Filament.
            ->saveUploadedFileUsing(function (TemporaryUploadedFile $file, SecureUploadService $uploads): ?string {
                $operador = auth()->user();

                if (! $operador instanceof AuthUser) {
                    return null;
                }

                try {
                    $upload = $uploads->handlePersonal($file, $operador, directory: self::DIRECTORY, allowedTypes: ['image']);
                } catch (UploadRejectedException) {
                    // A regra SafeFile já barrou este caso na validação; se
                    // chegou aqui, o arquivo mudou entre uma e outra: some
                    // sem gravar nada.
                    return null;
                }

                // Enviado agora, por este formulário: pode virar a foto de
                // qualquer conta que o operador estiver editando.
                app(FreshAvatarUploads::class)->remember($upload->uuid);

                return $upload->uuid;
            });
    }

    /**
     * Motivo da recusa quando o campo aponta para um upload que não pode virar
     * a foto de `$user` (null na criação: a conta ainda não existe, então só
     * um upload enviado agora serve). Null = pode gravar.
     */
    public static function denialFor((Model&AuthUser)|null $user, mixed $state): ?string
    {
        $uuids = self::uuidsIn($state);

        if ($uuids === [] || ! self::available()) {
            return null;
        }

        $uploads = Upload::query()->whereIn('uuid', $uuids)->get();

        foreach ($uploads as $upload) {
            if (! self::mayBecomeAvatarOf($upload, $user)) {
                return __('admin.users.avatar_not_owned');
            }
        }

        return null;
    }

    /**
     * O que o formulário devolve no campo (uuid de Upload novo, caminho do
     * avatar atual, ou vazio) virando o vínculo do usuário.
     *
     * - uuid de upload novo  → troca a foto (a conferência do dono é de
     *                          denialFor(), feita antes pelas páginas);
     * - caminho já existente → mantém a que está lá (o usuário não mexeu);
     * - vazio                → remove a foto (o vínculo volta a null e as
     *                          iniciais assumem — InitialsAvatarProvider).
     */
    public static function applyTo(Model&AuthUser $user, mixed $state): void
    {
        if (! self::available()) {
            return;
        }

        $valores = self::valuesIn($state);

        if ($valores === []) {
            $user->forceFill(['avatar_upload_id' => null])->save();
            $user->unsetRelation('avatar');

            return;
        }

        $uuids = self::uuidsIn($valores);

        if ($uuids === []) {
            // Só caminhos de arquivo já existentes: nada mudou.
            return;
        }

        // Vence o Upload mais novo: é o que a pessoa acabou de escolher. (Quem
        // grava pelo formulário já perguntou denialFor() antes — upload
        // alheio não chega aqui.)
        $upload = Upload::query()->whereIn('uuid', $uuids)->orderByDesc('id')->first();

        if ($upload === null) {
            // uuid que não corresponde a Upload nenhum: nada a vincular.
            return;
        }

        $user->forceFill(['avatar_upload_id' => $upload->id])->save();

        // A relação pode ter sido carregada (vazia) antes da troca — o
        // avatar do cabeçalho é desenhado na MESMA resposta e mostraria as
        // iniciais de novo, como se o upload não tivesse funcionado.
        $user->unsetRelation('avatar');
    }

    /**
     * Estado inicial do campo ao abrir o formulário: o caminho do avatar
     * atual, para o Filament desenhar a prévia.
     */
    public static function stateFor((Model&AuthUser)|null $user): ?string
    {
        // Consulta direta, não `$user->avatar->path`: a relação pode estar
        // carregada e desatualizada na instância autenticada.
        return $user === null || ! self::available() ? null : $user->avatar()->value('path');
    }

    /**
     * O upload pode virar a foto desta pessoa?
     *
     * - enviado agora, por este formulário, nesta requisição;
     * - já é a foto atual dela;
     * - é dela: foto pessoal que ela mesma enviou, ou upload da CONTA
     *   PESSOAL dela (enviado por ela na web ou pela chave de API dela).
     */
    private static function mayBecomeAvatarOf(Upload $upload, (Model&AuthUser)|null $user): bool
    {
        if (app(FreshAvatarUploads::class)->has((string) $upload->uuid)) {
            return true;
        }

        if ($user === null || ! $user->exists) {
            return false;
        }

        $current = $user->getAttribute('avatar_upload_id');

        if ($current !== null && (string) $current === (string) $upload->id) {
            return true;
        }

        if ($upload->isPersonal()) {
            return $upload->created_by !== null && (string) $upload->created_by === (string) $user->getKey();
        }

        $pessoal = app(AccountService::class)->personalAccountOf($user);

        return $pessoal !== null && $upload->account_id !== null && (string) $upload->account_id === (string) $pessoal->getKey();
    }

    /**
     * @return list<string>
     */
    private static function valuesIn(mixed $state): array
    {
        return array_values(array_filter(
            is_array($state) ? $state : [$state],
            fn ($valor): bool => is_string($valor) && $valor !== '',
        ));
    }

    /**
     * Só os uuids do campo. O campo pode carregar o caminho da foto ATUAL
     * junto com o uuid da recém-enviada: no PostgreSQL a coluna é `uuid`
     * nativo e um caminho de arquivo na lista derruba a consulta inteira com
     * erro de sintaxe (o SQLite aceitava calado).
     *
     * @return list<string>
     */
    private static function uuidsIn(mixed $state): array
    {
        return UuidColumn::onlyValid(self::valuesIn($state));
    }
}
