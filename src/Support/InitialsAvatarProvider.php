<?php

declare(strict_types=1);

namespace Twstec\Kit\Admin\Support;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Avatar do menu do usuário do /admin.
 *
 * Duas correções sobre o padrão do Filament:
 *
 * 1. FOTO DE PERFIL. Quem já subiu avatar no painel do usuário (Upload
 *    validado pelo SecureUploadService) vê a própria foto no super admin — antes o painel
 *    ignorava a foto e desenhava iniciais para todo mundo. A resolução
 *    acontece AQUI, e não com um `getFilamentAvatarUrl()` no model User,
 *    porque o painel não deveria obrigar o model de domínio a conhecer o
 *    Filament (e o `avatar_url` que o Filament procura sozinho é um
 *    atributo que o kit não tem).
 *
 * 2. SEM CDN. O provider de fábrica aponta para ui-avatars.com: uma
 *    dependência externa, no caminho de renderização de toda página do
 *    painel, para desenhar duas letras. Aqui as iniciais viram um SVG
 *    inline em data: URI — nada sai da máquina, funciona offline e passa
 *    na CSP do kit (img-src 'self' data:).
 */
final class InitialsAvatarProvider implements AvatarProvider
{
    public function get(Model|Authenticatable $record): string
    {
        if (method_exists($record, 'avatarUrl') && filled($url = $record->avatarUrl())) {
            return (string) $url;
        }

        return self::initialsSvg(self::initials((string) Filament::getNameForDefaultAvatar($record)));
    }

    /**
     * Até duas iniciais: primeira e última palavra do nome. Sem nome
     * legível (conta recém-criada, nome só com espaços), cai no "?" — um
     * quadrado vazio parece bug de carregamento.
     */
    public static function initials(string $name): string
    {
        $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($words === []) {
            return '?';
        }

        $first = mb_strtoupper(mb_substr((string) reset($words), 0, 1));
        $last = count($words) > 1 ? mb_strtoupper(mb_substr((string) end($words), 0, 1)) : '';

        return $first.$last;
    }

    /**
     * SVG quadrado com as iniciais, em data: URI (base64 — evita qualquer
     * problema de escape do conteúdo dentro do atributo src).
     */
    public static function initialsSvg(string $initials): string
    {
        $text = htmlspecialchars($initials, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $svg = <<<SVG
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" width="64" height="64" role="img" aria-label="{$text}">
            <rect width="64" height="64" rx="32" fill="#18181b"/>
            <text x="32" y="33" fill="#fafafa" font-family="system-ui, -apple-system, Segoe UI, sans-serif" font-size="26" font-weight="600" text-anchor="middle" dominant-baseline="central">{$text}</text>
        </svg>
        SVG;

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
