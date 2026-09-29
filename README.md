# twstec/kit-admin

> **Parte do [TWS Laravel Starter Kit](https://github.com/kelvindk9w/tws-laravel-starter-kit).** O código, as issues e os
> pull requests ficam no monorepo
> [kelvindk9w/tws-laravel-starter-kit](https://github.com/kelvindk9w/tws-laravel-starter-kit) (pasta `packages/admin`); este
> repositório é o espelho só-leitura publicado a cada versão.
> Documentação: [docs/](https://github.com/kelvindk9w/tws-laravel-starter-kit/tree/desenvolvimento/docs) · Segurança:
> [SECURITY.md](SECURITY.md) · Licença: MIT ([LICENSE](LICENSE)).

O super admin do **TWS Laravel Starter Kit** (`/admin`) como **plugin do
Filament 5**: usuários, chaves de API, projetos, uploads, logs de requisição,
trilha de auditoria e configurações, login com verificação em duas etapas por
e-mail, variantes de dashboard, a trilha de auditoria de toda ação do painel
(que falha fechada) e as proteções de acesso — ligadas pelo pacote, não pelo
aplicativo.

É a camada de cima do kit e o único pacote com telas: depende do
[`twstec/kit-auth`](https://github.com/kelvindk9w/tws-laravel-starter-kit/tree/desenvolvimento/packages/auth), do [`twstec/kit-foundation`](https://github.com/kelvindk9w/tws-laravel-starter-kit/tree/desenvolvimento/packages/foundation), do
Laravel e do Filament (com o Livewire, que o Filament usa). O
[`twstec/kit-accounts`](https://github.com/kelvindk9w/tws-laravel-starter-kit/tree/desenvolvimento/packages/accounts) e o [`twstec/kit-uploads`](https://github.com/kelvindk9w/tws-laravel-starter-kit/tree/desenvolvimento/packages/uploads) são
**sugeridos** (`suggest`), não exigidos: o painel se adapta ao que está
instalado (abaixo). Não conhece o
aplicativo — o model de usuário é o configurado em
`auth.providers.users.model` — e um teste de arquitetura na suíte do pacote
garante isso.

- **Requisitos:** PHP 8.4+, Laravel 13, Filament 5, Livewire 4,
  `twstec/kit-foundation` e `twstec/kit-auth` 2.x (e, se quiser as telas
  deles, `twstec/kit-accounts` e `twstec/kit-uploads` 2.x).
- **Licença:** MIT.

## O que o pacote traz

| Peça | O que faz |
| --- | --- |
| `AdminPlugin` | O plugin que o aplicativo registra no painel: resources, páginas, login, segundo fator, dashboards, navegação, menu do usuário, avatar de iniciais e as proteções |
| `Resources\…` | Usuários (CRUD com guardas), Contas (só leitura: membros e papéis, projetos e chaves da conta), Chaves de API, Projetos, Uploads, Logs de requisição e Auditoria |
| `Pages\…` | Perfil do admin (foto, nome, segundo fator), Configurações editáveis e o Login (`Pages\Auth\Login`) |
| `Auth\EmailCodeAuthentication` | Provedor de MFA do Filament com o motor do `twstec/kit-auth` (código por e-mail, mesmos limites do painel do cliente) |
| `Dashboards\…`, `Widgets\…` | As variantes "Visão geral" e "Crescimento & API", o `DashboardRegistry` (lê `config/dashboards.php`) e a base de widgets (`Metric`, `Period`, `BaseStatsWidget`…) |
| `Support\AdminAudit` | A trilha de auditoria das ações: toda chamada Livewire de tela do painel roda com um escopo aberto; o `AuditTrail` do foundation grava cada escrita de model |
| `Support\AdminPanelHardening` | As garantias de segurança do painel, aplicadas pelo pacote qualquer que seja a ordem do `PanelProvider` |
| `Access\AdminAccess`, `Concerns\AccessesAdminPanel`, `Http\Middleware\EnsureAdminPanelAccess` | Quem entra: `is_admin` + conta ativa — o critério, a trait do `canAccessPanel` e a conferência do pacote |
| `Resources\Users\Support\UserAdminGuard`, `MarkEmailVerifiedAction` | Guardas de servidor (conta protegida, a própria conta, o último admin ativo) e a ação de suporte |
| `Support\AvatarUpload` | O campo de foto: grava pela função global de upload (foto pessoal) e só vincula upload da própria pessoa |
| `Console\MakeAdminUser` | `php artisan user:make-admin email [--remove]` — o resgate de acesso, auditado no contexto console |

## Módulos opcionais: o painel se adapta

O plugin pergunta ao ponto único de detecção do kit
(`Twstec\Kit\Foundation\Kit::has()`) o que está instalado e só registra o que
existe (`AdminPlugin::RESOURCES` diz qual tela é de qual módulo):

| Sem… | O painel |
| --- | --- |
| `twstec/kit-accounts` | Sem as telas de contas, chaves de API e projetos (nem menu, nem rota); sem o filtro por conta na trilha de auditoria; os dashboards sem os cards de chaves/projetos e sem a tabela de projetos recentes; sem o modo sistema das contas (não há contas); a guarda de exclusão de usuário não consulta contas |
| `twstec/kit-uploads` | Sem a tela de uploads e o widget dos últimos uploads; sem o campo de foto no cadastro de usuário e no perfil do admin (o avatar são as iniciais) |

Uma trava de arquitetura da suíte (`DependenciesTest`) confere que todo
arquivo do pacote que nomeia uma classe de `accounts` ou `uploads` é uma tela
registrada só com o módulo ou pergunta `Kit::has()` antes. A suíte finge a
ausência (`Kit::pretendAbsent`) e sobe o painel de novo (`OptionalModulesTest`);
a prova com os pacotes ausentes de verdade é a suíte do starter em cada
combinação, no CI.

## Instalação

Pelo Packagist:

```bash
composer require "twstec/kit-admin:^2.0@beta"   # durante o beta; na 2.0.0 estável, ^2.0
```

Durante o beta, cada pacote do kit que você requerer leva o `@beta` (ou o
projeto declara `"minimum-stability": "beta"` com `"prefer-stable": true`) —
ver [docs/instalacao.md](https://github.com/kelvindk9w/tws-laravel-starter-kit/blob/desenvolvimento/docs/instalacao.md).

**No monorepo** (desenvolvimento do próprio kit), o starter instala o pacote por
*path repository* — como os
outros (`"url": "../../packages/admin"`, `"twstec/kit-admin": "2.x-dev"`).

O `AdminServiceProvider` é descoberto automaticamente. O aplicativo precisa de:

1. **Um painel com o plugin** — o registro mínimo:

   ```php
   use Twstec\Kit\Admin\AdminPlugin;

   class AdminPanelProvider extends PanelProvider
   {
       public function panel(Panel $panel): Panel
       {
           return $panel
               ->default()
               ->id('admin')
               ->path('admin')
               ->plugin(AdminPlugin::make());
       }
   }
   ```

2. **O model de usuário** implementando `Filament\Models\Contracts\FilamentUser`
   com a trait `Twstec\Kit\Admin\Concerns\AccessesAdminPanel` (além das do
   `twstec/kit-auth` e do `HasAvatar` do `twstec/kit-uploads`), e as colunas
   `users.is_admin` (boolean, padrão `false`) e `users.avatar_upload_id` numa
   migration do aplicativo. O pacote não traz migration.

3. **O tema** (opcional, para o visual do kit): um tema Vite do Filament que
   importe as fontes do pacote — ver [Tema e CSS](#tema-e-css).

Depois: `php artisan user:make-admin email@exemplo.com` para o primeiro admin.

## Personalizar

O painel é do aplicativo: marca, cores, fonte, caminho, id e o que mais for
do Filament vão no `PanelProvider`, antes ou depois do plugin (o que vier
depois sobrescreve o que o plugin declarou):

```php
$panel
    ->default()->id('admin')->path('painel')
    ->plugin(AdminPlugin::make())
    ->brandName('Minha Empresa')
    ->colors(['primary' => Color::Indigo])
    ->viteTheme('resources/css/filament.css')
    // telas próprias do aplicativo
    ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
    // outro plugin, como a demonstração do kit faz
    ->plugin(MeuPlugin::make());
```

Configuração (`config/admin.php` e `config/dashboards.php`, publicáveis com
`--tag=admin-config`; a do aplicativo vence chave a chave):

| Chave | Padrão | O que faz |
| --- | --- | --- |
| `admin.protections` | `true` (`ADMIN_PROTECTIONS`) | Opt-out das proteções do painel (ver abaixo), com aviso no log a cada boot |
| `dashboards.enabled` | `overview,growth` (`DASHBOARD_ENABLED`) | Variantes ligadas, na ordem do menu |
| `dashboards.default` | `overview` | A variante que responde na raiz do painel |
| `dashboards.variants` | as duas do pacote | Catálogo slug → página + ícone + ordem; uma variante nova entra aqui |
| `dashboards.widgets` | `[]` | Widgets que extensões acrescentam a uma variante (com posição) |

Traduções: o grupo `admin.*` (e as poucas chaves de `auth.*` e `panel.*` que
as telas usam e que nenhum pacote de baixo traz) vem do pacote; **o `lang/` do
aplicativo vence** na mesma chave, em todo idioma e no de reserva. Um bloco
novo (`admin.faturas`, por exemplo) vai no `lang/admin.php` do aplicativo.

## Acrescentar um resource auditado

1. Escreva o resource no aplicativo (`app/Filament/Resources/…`), estendendo
   `Twstec\Kit\Admin\Support\BaseResource` e a listagem de
   `Twstec\Kit\Admin\Support\BaseListRecords` — rota por uuid, rótulos
   traduzidos, paginação e o alternador tabela/cards vêm da base.
2. Registre-o no painel (`discoverResources` ou `->resources([...])`).
3. **A auditoria já vale**: as telas de `<namespace do app>\Filament\` (no
   starter, `App\Filament\`) e as do Filament abrem o escopo de auditoria como
   as do pacote. Toda escrita pelo model (`save`, `update`, `delete`, as
   Actions de criar/editar/excluir) vira uma linha em `audit_events`.
   Tela vinda de outro pacote ou extensão entra declarando o namespace em
   `audit.admin_extension_namespaces` (no `register` da extensão).
4. Duas regras: **escreva pelo model** (`DB::`, query em massa, `*Quietly()` e
   `withoutEvents()` não geram linha) e **recuse com
   `AdminAudit::denied($motivo, $record)`** (registra a tentativa como
   `denied` e mostra a notificação numa chamada só). O teste de arquitetura
   do pacote cobra as duas no código dele; o starter cobra no dele, e a suíte
   da demonstração (twstec/kit-demo) no dela.

## O que ele liga sozinho

Em **todo painel que registra o plugin**, sem nenhuma linha no aplicativo e
qualquer que seja a ordem do `PanelProvider` (`Support\AdminPanelHardening`,
aplicado no registro do plugin e de novo depois que o Filament montou os
painéis):

- **Barreira de origem** — a allowlist de IP do `twstec/kit-foundation`
  (`security.admin.allowed_ips`, `ADMIN_ALLOWED_IPS`; em produção sem lista o
  painel recusa) é o **primeiro** middleware do painel e é **persistente** no
  endpoint de atualização do Livewire: vale para abrir a página e para
  executar a ação dela. Também entra no grupo `filament.actions` (o download
  de exports/imports do Filament, fora do painel).
- **Só administrador com conta ativa** — o `Authenticate` do Filament e o
  `EnsureAdminPanelAccess` do pacote, os dois persistentes. O pacote confere
  mesmo que o model não implemente o contrato do Filament (em ambiente local o
  Filament deixaria qualquer conta entrar).
- **Pilha sem repetição** — um `PanelProvider` copiado do modelo do Filament,
  com a pilha de sessão/CSRF inteira, continua funcionando: nenhum middleware
  entra duas vezes.
- **Transação** — Actions e Criar/Salvar em transação: a linha da trilha é
  gravada na mesma transação da mudança, e sem ela a mudança é desfeita
  (falha fechada). Recusa das guardas confirma a transação — a tentativa
  recusada fica registrada.
- **Trilha de auditoria** das ações do painel (`AdminAudit`, ligada no boot do
  provider) e o **segundo fator** por e-mail no login.
- **Modo sistema das contas** (`twstec/kit-accounts`) — o painel vê projetos e
  chaves de **todas** as contas: o `OperateAdminPanelAsSystem` entra na
  autenticação do painel, depois do acesso de admin, e é persistente. Ele
  declara o modo sistema **da requisição** (`Accounts::systemModeForRequest`)
  em vez de envolver o `$next`: nas ações, o Livewire reaplica os middlewares
  persistentes num pipeline à parte, antes do componente — um modo sistema só
  em volta do `$next` terminaria antes da ação. O fim da requisição o desfaz.
  As telas de projetos e chaves mostram a **conta** de cada linha (código
  `ACC-…`, com filtro), o dono da conta e quem criou; a exclusão de pessoa
  **dona de conta com outros membros** é recusada (ação escondida e, forjada,
  recusada com o motivo na trilha).

Opt-out só explícito: `ADMIN_PROTECTIONS=false` desliga a barreira de origem
do painel, a conferência de acesso do pacote e a transação obrigatória — o
aplicativo assume as três — e o pacote grava um **aviso no log a cada boot**.
A trilha, o segundo fator e o modo sistema das contas continuam ligados (sem
ele as telas de projetos e chaves não teriam conta atual).

**Telas próprias do aplicativo no painel** que leem dado de conta rodam no
mesmo modo sistema (a requisição é do painel). Um teste com `Livewire::test`
de uma tela do painel não passa pela pilha HTTP: declare o modo sistema no
teste (o starter faz isso para `tests/Feature/Admin` no `tests/Pest.php`).

O dashboard de filas (`/horizon`) é do aplicativo (o Horizon não é
dependência do pacote), mas o gate `viewHorizon` do starter lê o mesmo
critério: `AdminAccess::allows($user)`.

## Tema e CSS

O pacote **não entrega CSS compilado**: o tema do painel é do aplicativo (os
tokens de marca dele + o preset do Filament), compilado pelo Vite dele. O
pacote entrega `resources/css/sources.css`, que só diz ao Tailwind 4 onde
estão as classes das telas do pacote. O tema do aplicativo o importa:

```css
@import '../../vendor/filament/filament/resources/css/theme.css';
@import '../../vendor/twstec/kit-admin/resources/css/sources.css';
```

Por ser um `@import`, o build **falha** se o pacote não estiver ao alcance —
em vez de gerar em silêncio um tema sem as classes do painel. Com o pacote
instalado por link (path repository), o build em container precisa montar a
pasta dos pacotes (no starter: `-v $(pwd)/../../packages:/packages`).

## Foto de perfil: só upload da própria pessoa

O campo de foto (cadastro de usuário e perfil do admin) grava pela função
global de upload do `twstec/kit-uploads` — como **foto pessoal**
(`SecureUploadService::handlePersonal`, sem conta, com o operador em
`created_by`: a foto é da pessoa e aparece em todas as contas dela) — e só
vincula como foto:

- um upload enviado **agora, naquele formulário** (o servidor o gravou nesta
  requisição — `Support\FreshAvatarUploads`);
- um upload **da própria pessoa**: foto pessoal que ela mesma enviou, upload
  da **conta pessoal** dela (pela web ou pela chave de API dela), ou a foto
  atual.

O valor do campo vem do navegador; apontá-lo para o upload de outra pessoa é
recusado **antes** de gravar qualquer coisa (nem os outros campos mudam), com a
recusa na trilha como `denied` (`user.updated`; na criação, `user.created`).
A regra está em `AvatarUpload::denialFor()`.

## Uploads e Auditoria por conta

O painel opera em modo sistema (todas as contas). A tela de **Uploads** mostra
a conta de cada linha (código, com filtro por conta), quem enviou e o tipo de
dono (da conta, foto pessoal, órfão — com filtro); a de **Auditoria** filtra
pela conta em que a ação aconteceu (`audit_events.tenant_uuid`, valor que não
é uuid não derruba a consulta). Excluir uma pessoa pelo painel apaga os
arquivos dela (regra do `twstec/kit-uploads`), com a linha `upload.erased` na
trilha em nome do operador.

## Nomes antigos → nomes novos

Até a 1.x o painel morava no aplicativo, em `App\Filament\…`. Os nomes antigos
continuam resolvendo até a **3.0** (apelido preguiçoso, lista fechada em
`Compat\LegacyNames` — as peças novas do pacote e uma tela que o aplicativo
escreve em `App\Filament\` não ganham nome antigo):

| Antigo | Novo |
| --- | --- |
| `App\Filament\<resto>` (as 59 classes do painel da 1.x) | `Twstec\Kit\Admin\<resto>` |
| `App\Console\Commands\MakeAdminUser`, `App\Core\Auth\Console\MakeAdminUser` | `Twstec\Kit\Admin\Console\MakeAdminUser` |

Onde o nome antigo pode estar gravado, e o que acontece:

- **snapshot de componente Livewire** aberto no navegador durante o deploy (o
  nome do componente de uma página do Filament é a classe): o Livewire acha a
  classe pelo nome antigo e a ação funciona;
- **config de dashboards publicada**: o `DashboardRegistry` aceita o nome antigo
  e registra sempre o novo;
- **estado de tabela** (itens por página, ordenação, filtros, busca, colunas —
  o Filament grava na sessão pelo nome da classe) e o **modo tabela/cards**:
  migram para o nome novo na primeira abertura da tela
  (`Support\LegacySessionState`, idempotente — uma escolha feita depois da
  atualização vence);
- **exports/imports do Filament e jobs**: o painel não tem exportação,
  importação nem job com o nome da classe; a trilha de auditoria grava o nome
  curto do model (`user`), nunca a classe.

## Lacunas conhecidas

- A coluna de código público das listagens pede `admin.common.code`, chave que
  nunca existiu (a 1.x já mostrava a chave crua). A extração manteve a tela
  idêntica; a correção é uma mudança de texto própria.

## Testes

A suíte do pacote (Pest + Orchestra Testbench) sobe uma aplicação Laravel
**limpa** com o Filament, o model de usuário mínimo e um `PanelProvider` que
só registra o plugin — nada do starter — e prova que as proteções e a trilha
vêm do pacote: não-admin e admin inativo sem acesso (inclusive com model sem o
contrato do Filament em ambiente local), allowlist de IP nas páginas, nas ações
Livewire e no download de exports, a barreira em primeiro lugar em qualquer
ordem do `PanelProvider`, criar/editar/excluir/bloquear usuário gravando a
linha com quem, registro, antes/depois redigido, IP, User-Agent e
`correlation_id`, recusa como `denied`, falha fechada, contas protegidas,
`user:make-admin`, foto só de upload da própria conta, nomes antigos,
traduções (o aplicativo vence) e a arquitetura (só os pacotes do kit, o
Laravel, o Filament e o Livewire).

```bash
docker compose exec -w /var/packages/admin app ./vendor/bin/pest
docker compose exec -w /var/packages/admin app ./vendor/bin/pint --test
```
