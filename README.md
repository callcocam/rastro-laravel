# Rastro para Laravel

O chat de suporte do [**Rastro**](https://rastro.support) no seu sistema Laravel. O seu cliente pergunta sem sair da tela, e a sua equipe recebe a dúvida junto com o **rastro** dele: as telas que abriu, os cliques, os erros de validação, as falhas e os erros do servidor dos últimos minutos. Senhas e dados sensíveis ficam de fora.

As conversas chegam na caixa de entrada do Rastro, junto com as do WhatsApp e do e-mail. A IA do Rastro responde com base na sua árvore de dúvidas e passa para uma pessoa quando não sabe.

- **Site e cadastro:** https://rastro.support
- **Guia de instalação (com texto pronto para a sua IA):** https://rastro.support/ajuda/instalar-o-chat
- **Central de Ajuda:** https://rastro.support/ajuda

---

## Sumário

- [Como funciona](#como-funciona)
- [Em quais sistemas funciona](#em-quais-sistemas-funciona)
- [Requisitos](#requisitos)
- [Instalação rápida com a sua IA](#instalação-rápida-com-a-sua-ia)
- [Instalação passo a passo](#instalação-passo-a-passo)
- [Exemplos](#exemplos)
- [Aparência](#aparência)
- [O que vai junto com a dúvida](#o-que-vai-junto-com-a-dúvida)
- [Privacidade e LGPD](#privacidade-e-lgpd)
- [Árvore de dúvidas pela IA](#árvore-de-dúvidas-pela-ia)
- [Configuração](#configuração)
- [Rotas](#rotas)
- [Comandos](#comandos)
- [Atualizar o pacote](#atualizar-o-pacote)
- [Problemas comuns](#problemas-comuns)
- [Para agentes de IA](#para-agentes-de-ia)
- [Desenvolvimento do pacote](#desenvolvimento-do-pacote)

---

## Como funciona

```
 Navegador do cliente            Seu sistema Laravel                 Rastro
 ┌──────────────────┐  mesma   ┌──────────────────────────┐  chave  ┌──────────────────┐
 │ RastroWidget.vue │ ───────▶ │ /support/widget/*        │ ──────▶ │ caixa de entrada │
 │ rastro do        │  origem  │ (sessão e login vão      │ RASTRO_ │ IA + árvore de   │
 │ navegador        │ ◀─────── │  juntos) + rastro do     │ ◀────── │ dúvidas + equipe │
 └──────────────────┘          │ servidor                 │   KEY   └──────────────────┘
                               └──────────────────────────┘
```

1. O chat fala **só com o seu próprio sistema**, no mesmo domínio. A sessão e o login do cliente vão juntos, e não precisa de CORS.
2. O seu sistema repassa a conversa para a central do Rastro, de servidor para servidor, com a chave do site (`RASTRO_KEY`). **A chave nunca vai para o navegador.**
3. Junto com cada mensagem vão quem está logado (nome, e-mail e id), o rastro do navegador e o rastro do servidor.
4. O pacote **não cria tabelas nem telas**. Ele não guarda as conversas: elas ficam no Rastro.

## Em quais sistemas funciona

| Sistema                                      | Como o chat entra                                   | Situação |
| -------------------------------------------- | --------------------------------------------------- | -------- |
| Laravel com Blade, Livewire ou Inertia React | Tag `<x-rastro::widget />` no layout                | Pronto   |
| Painel Filament (v3 e v4)                    | Plugin `RastroPlugin` no painel                     | Pronto   |
| Laravel com Inertia e Vue 3                  | Componente `RastroWidget.vue` ou a tag Blade        | Pronto   |
| Site sem Laravel (só JavaScript)             | `<script>` com a chave pública do site              | Em breve |

Nos três primeiros, o chat tem tudo: o login reconhecido, o rastro do servidor e a árvore de dúvidas pela IA. O chat da tag e do plugin já vem compilado, com o Vue dentro, e é servido pelo próprio pacote: o seu sistema não precisa ter Vue nem compilar nada.

## Requisitos

- PHP 8.3 ou mais novo
- Laravel 11, 12 ou 13
- Sessão no grupo `web` (o padrão do Laravel)
- Para o plugin: Filament 3 ou 4. Para o componente Vue: Inertia com Vue 3, compilado pelo Vite do seu projeto
- Uma central no [Rastro](https://rastro.support) e uma **chave do site**, criada no painel da central em **Chaves dos sites**

## Instalação rápida com a sua IA

Se você programa com uma IA (Claude Code, Cursor, Copilot ou outra), abra o guia https://rastro.support/ajuda/instalar-o-chat, copie o texto da parte **"Jeito rápido: peça para a sua IA"** e cole no chat dela, aberta no seu projeto. Ela faz a instalação e pede a chave na hora certa.

## Instalação passo a passo

### 1. Crie a chave do site

No painel da sua central no Rastro, em **Chaves dos sites**, crie uma chave com o nome do sistema. **Copie na hora: ela só aparece uma vez.** Cada sistema usa uma chave própria.

### 2. Instale o pacote

O pacote ainda não está no Packagist, então diga ao Composer onde ele fica:

```bash
composer config repositories.rastro vcs https://github.com/callcocam/rastro-laravel
composer require callcocam/rastro-laravel:dev-main
```

O service provider é registrado sozinho (package discovery).

### 3. Coloque a chave no `.env`

O painel mostra as duas linhas prontas para copiar, em **Instalar o chat**:

```dotenv
RASTRO_URL=https://rastro.support/support/api
RASTRO_KEY=rk_a-chave-que-voce-criou
```

No `.env.example`, deixe as duas vazias. **Nunca faça commit da chave.**

### 4. Coloque o chat no seu sistema

Escolha o jeito do seu tipo de sistema. Coloque o chat **uma vez só** por página.

#### Blade, Livewire ou Inertia com React

Coloque a tag antes do `</body>` do layout principal. Em sistemas Inertia, o layout é o `resources/views/app.blade.php`:

```blade
    <x-rastro::widget />
</body>
```

Não precisa compilar nada: o pacote serve o chat em `/support/widget/rastro-widget.js`. Com `wire:navigate` (Livewire) ou Turbo, o chat continua aberto na troca de tela.

#### Filament

Registre o plugin em cada painel em que o chat deve aparecer:

```php
// app/Providers/Filament/AdminPanelProvider.php
use Callcocam\Rastro\Filament\RastroPlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        // ...
        ->plugin(RastroPlugin::make());
}
```

O plugin coloca o chat no fim de todas as páginas do painel. Não precisa mexer em nenhum Blade.

#### Inertia com Vue 3

A tag Blade acima também funciona aqui, e é o jeito mais simples: coloque `<x-rastro::widget />` no `resources/views/app.blade.php`. Se preferir o chat dentro da árvore do Vue, publique o componente:

```bash
php artisan vendor:publish --tag=rastro-widget
```

Isso cria `resources/js/components/rastro/RastroWidget.vue`. Coloque o componente no layout:

```vue
<!-- resources/js/layouts/AppLayout.vue -->
<script setup lang="ts">
import RastroWidget from '@/components/rastro/RastroWidget.vue';
</script>

<template>
    <div>
        <!-- o resto do seu layout -->
        <slot />
        <RastroWidget />
    </div>
</template>
```

> Não edite o `RastroWidget.vue` publicado. Para mudar as cores, use as variáveis CSS (veja [Aparência](#aparência)). Uma cópia publicada não recebe as atualizações sozinha: a cada versão nova, publique de novo (veja [Atualizar o pacote](#atualizar-o-pacote)). Com a tag Blade, isso não é preciso.

### 5. Compile e teste

```bash
php artisan config:clear   # se o projeto usa cache de configuração
npm run build              # só se você publicou o RastroWidget.vue
```

Abra o sistema. O botão do chat aparece no canto da tela. Mande uma mensagem de teste: ela chega na caixa de entrada do Rastro. Se a pessoa estiver logada, o nome e o e-mail já vêm preenchidos.

## Exemplos

### Só para quem está logado

```blade
@auth
    <x-rastro::widget />
@endauth
```

No Vue, com o componente publicado:

```vue
<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import RastroWidget from '@/components/rastro/RastroWidget.vue';

const page = usePage();
const loggedIn = computed(() => Boolean(page.props.auth?.user));
</script>

<template>
    <div>
        <slot />
        <RastroWidget v-if="loggedIn" />
    </div>
</template>
```

### Nas telas logadas e no site público

Coloque o chat em cada layout em que ele deve aparecer: por exemplo, no layout do painel e no das telas de login e cadastro. A conversa continua entre as telas e depois de recarregar a página, porque fica guardada no navegador.

### React: escolher quando o chat aparece

A tag monta o chat sozinha. Para decidir no React quando ele aparece, carregue o script em modo manual e monte pelo `window.Rastro`:

```blade
{{-- resources/views/app.blade.php --}}
<script src="{{ \Callcocam\Rastro\Widget::scriptUrl() }}" data-manual defer></script>
```

```tsx
import { useEffect } from 'react';

declare global {
    interface Window {
        Rastro?: { mount: (options?: { endpoint?: string; storageKey?: string }) => unknown; unmount: () => void };
    }
}

export function RastroChat() {
    useEffect(() => {
        window.Rastro?.mount({ endpoint: '/support/widget' });

        return () => window.Rastro?.unmount();
    }, []);

    return null;
}
```

Coloque `<RastroChat />` só nas telas em que o chat deve aparecer.

### Outro endereço para o chat

Por padrão, o chat conversa com o seu sistema em `/support/widget`. Se esse endereço já está em uso:

```dotenv
RASTRO_WIDGET_PREFIX=ajuda/chat
```

A tag Blade e o plugin do Filament usam o endereço novo sozinhos. No componente Vue, passe o endereço:

```vue
<RastroWidget endpoint="/ajuda/chat" />
```

### Dois sistemas no mesmo domínio

A conversa fica guardada no navegador com o nome `rastro`. Se dois sistemas seus rodam no mesmo domínio, dê um nome diferente para cada um, para as conversas não se misturarem:

```blade
<x-rastro::widget storage-key="rastro-financeiro" />
```

```vue
<RastroWidget storage-key="rastro-financeiro" />
```

### Desligar o rastro do servidor

```dotenv
RASTRO_CONTEXT=false             # desliga o rastro do servidor
RASTRO_CONTEXT_LOG_LEVEL=        # mantém o rastro, mas sem os erros do log
RASTRO_CONTEXT_MIDDLEWARE=false  # mantém o log, sem o middleware em `web`
```

## Aparência

A cor principal, o canto da tela (direita ou esquerda) e o tema (claro, escuro ou igual ao site) são escolhidos no painel do Rastro, em **Chat do site**. A mudança chega ao chat em até um minuto, sem deploy.

Para seguir as cores do seu sistema, defina as variáveis `--wsw-*` no CSS do seu projeto. Elas valem mais do que a cor escolhida no painel:

```css
/* resources/css/app.css */
:root {
    --wsw-accent: #e11d48;
    --wsw-accent-text: #ffffff;
}

.dark {
    --wsw-accent: #fb7185;
    --wsw-accent-text: #1c0a10;
}
```

Todas as variáveis, um exemplo completo de claro e escuro e um roteiro para a IA estão em [CUSTOMIZING.md](CUSTOMIZING.md).

## O que vai junto com a dúvida

Para o atendente não precisar perguntar "em que tela você estava?", cada mensagem leva:

| De onde        | O quê                                                                                                    |
| -------------- | -------------------------------------------------------------------------------------------------------- |
| Navegador      | Telas abertas, cliques, formulários enviados, erros de validação, requisições que falharam e erros de JavaScript |
| Servidor       | Formulários devolvidos com erro de validação (nome do campo e mensagem), respostas 4xx e 5xx, e erros do log a partir de `error`, com a classe da exceção e o `arquivo:linha` do seu código |
| Quem é         | Nome, e-mail e id de quem está logado, e o navegador usado                                               |
| Árvore de dúvidas | O caminho que a pessoa seguiu antes de chamar alguém                                                  |

O rastro do navegador fica no `sessionStorage`. O do servidor fica no cache do Laravel, por sessão, pelos últimos `RASTRO_CONTEXT_MINUTES` minutos (padrão 30), com até 40 eventos.

## Privacidade e LGPD

Tudo o que vai no rastro passa por um filtro:

- **O que a pessoa digita nunca vai.** Vão só os nomes dos campos e as mensagens de erro.
- **Campos sensíveis ficam ocultos**, pelo nome: `password`, `senha`, `token`, `secret`, `authorization`, `cookie`, `api_key`, `card`, `cartao`, `cvv`, `cpf`, `cnpj`. A lista fica em `rastro.context.hidden_keys`.
- **Sequências longas de números** (CPF, cartão, telefone) são mascaradas e mostram só os dois últimos dígitos.
- **Tokens** (`Bearer …`, `sk_…`, `pk_…`, `rk_…`) são trocados por `[oculto]`.
- **As URLs vão sem os valores:** `/cadastro?step=2&email=x` vira `/cadastro?step&email`.
- Do log vão só a mensagem, a classe da exceção e a linha. O contexto do log não é copiado.

Para a LGPD, a sua empresa é a **controladora** desses dados e o Rastro é o **operador**. Avise os seus clientes, por exemplo na política de privacidade do seu sistema, que o chat registra as telas, os cliques e os erros. Veja os [Termos de Uso](https://rastro.support/termos) e a [Política de Privacidade](https://rastro.support/privacidade) do Rastro.

## Árvore de dúvidas pela IA

A árvore de dúvidas são as opções que o chat mostra antes de chamar uma pessoa, por exemplo "Acesso e senha › Esqueci minha senha". A IA do Rastro **responde só com o que está nela**. O pacote ajuda a montar a primeira versão a partir do seu sistema, de dois jeitos. Nos dois, tudo entra na central como **rascunho**, e o cliente só vê depois que a sua equipe revisa e publica em **Árvore de dúvidas**.

### Com a sua IA de programação

```bash
php artisan rastro:help-guide   # cria RASTRO-AJUDA.md na raiz do projeto
```

Peça para a sua IA: **"Siga o RASTRO-AJUDA.md"**. Ela lê o projeto e escreve `rastro-ajuda.json`:

```json
{
  "topics": [
    {
      "label": "Acesso e senha",
      "prompt": "Qual é a sua dúvida sobre acesso?",
      "children": [
        {
          "label": "Esqueci minha senha",
          "answer": "1. Na tela de entrada, clique em \"Esqueci minha senha\".\n2. Digite o seu e-mail.\n3. Abra o e-mail que chegou e escolha a nova senha.",
          "help_url": "https://exemplo.com.br/ajuda/senha"
        }
      ]
    }
  ]
}
```

Revise o arquivo e mande para a central:

```bash
php artisan rastro:help-push                 # usa rastro-ajuda.json da raiz
php artisan rastro:help-push caminho/arquivo.json
```

As regras: até 3 níveis e 500 opções; toda opção sem `children` precisa de `answer` ou de `help_url`. Mandar o arquivo de novo atualiza os rascunhos com o mesmo `label` e não mexe no que já foi publicado. O formato completo está no `RASTRO-AJUDA.md`.

### Com a IA do Rastro

```bash
php artisan rastro:scan --show   # mostra o que seria enviado, sem enviar
php artisan rastro:scan          # lê o projeto e manda para a central montar as sugestões
php artisan rastro:scan --locale=pt_BR
```

O `rastro:scan` lê, no seu servidor, só: as rotas `web`, as regras de validação (Form Requests e arrays de regras em `app/`), as traduções do idioma e os nomes das páginas (`resources/js/pages`). **Ele nunca lê** `.env`, `config/`, `storage/`, `database/`, `vendor/`, `node_modules/` nem os testes, mesmo que estejam na configuração. As sugestões usam as respostas de IA do seu plano.

## Configuração

Para mudar além do `.env`, publique o arquivo de configuração:

```bash
php artisan vendor:publish --tag=rastro-config
```

| Variável do `.env`          | Padrão           | O que faz                                                          |
| --------------------------- | ---------------- | ------------------------------------------------------------------ |
| `RASTRO_URL`                | —                | Endereço da API da central: `https://rastro.support/support/api`   |
| `RASTRO_KEY`                | —                | A chave do site, criada no painel                                  |
| `RASTRO_TIMEOUT`            | `10`             | Segundos de espera pela central                                    |
| `RASTRO_ROUTES`             | `true`           | Registra as rotas do chat. Com `false`, você registra (veja [Rotas](#rotas)) |
| `RASTRO_WIDGET_PREFIX`      | `support/widget` | Endereço das rotas do chat no seu sistema                          |
| `RASTRO_WIDGET_DOMAIN`      | —                | Prende as rotas do chat a um domínio                               |
| `RASTRO_CONTEXT`            | `true`           | Liga o rastro do servidor                                          |
| `RASTRO_CONTEXT_MINUTES`    | `30`             | Quantos minutos de rastro guardar                                  |
| `RASTRO_CONTEXT_CACHE`      | cache padrão     | Store de cache do rastro (por exemplo, `redis`)                    |
| `RASTRO_CONTEXT_MIDDLEWARE` | `true`           | Coloca o middleware do rastro no grupo `web`                       |
| `RASTRO_CONTEXT_LOG_LEVEL`  | `error`          | A partir de qual nível o log entra no rastro. Vazio desliga        |

No arquivo de configuração ficam também a lista `context.hidden_keys`, o limite `context.max_events`, o middleware das rotas (`widget.middleware`, padrão `web` e `throttle:60,1`) e o que o `rastro:scan` lê (`scan.*`).

Para traduzir ou mudar as mensagens do pacote: `php artisan vendor:publish --tag=rastro-lang`. Os textos do chat em si vêm da central.

## Rotas

Com `RASTRO_ROUTES=true` (padrão), o pacote registra, com o prefixo `support/widget` e os middlewares `web` e `throttle:60,1`:

| Método | Endereço                                  | Nome                          |
| ------ | ----------------------------------------- | ----------------------------- |
| GET    | `/rastro-widget.js`                       | `rastro.widget.script`        |
| GET    | `/config`                                 | `rastro.widget.config`        |
| POST   | `/conversations`                          | `rastro.widget.store`         |
| GET    | `/conversations/{conversation}`           | `rastro.widget.show`          |
| POST   | `/conversations/{conversation}/messages`  | `rastro.widget.message`       |
| POST   | `/conversations/{conversation}/handoff`   | `rastro.widget.handoff`       |
| POST   | `/conversations/{conversation}/close`     | `rastro.widget.close`         |
| POST   | `/conversations/{conversation}/rating`    | `rastro.widget.rating`        |
| POST   | `/help/{node}/feedback`                   | `rastro.widget.help.feedback` |

O `/rastro-widget.js` é o chat compilado, usado pela tag Blade e pelo plugin do Filament. Ele não passa pela sessão nem pelo limite de requisições, e o navegador guarda o arquivo até a próxima versão do pacote.

O chat manda o token CSRF (`X-XSRF-TOKEN`) como qualquer requisição do Laravel, então nada precisa sair da proteção de CSRF. Cada conversa é protegida por um token próprio (`X-Support-Token`), guardado no navegador de quem abriu a conversa.

Para registrar as rotas você mesmo, por exemplo com outro middleware (inclua também a do script, se usar a tag Blade ou o Filament):

```php
// routes/web.php
use Callcocam\Rastro\Http\WidgetRelayController;

Route::get('support/widget/rastro-widget.js', \Callcocam\Rastro\Http\WidgetScriptController::class)->name('rastro.widget.script');

Route::prefix('support/widget')->middleware(['web', 'auth', 'throttle:60,1'])->name('rastro.widget.')->group(function () {
    Route::get('/config', [WidgetRelayController::class, 'config'])->name('config');
    Route::post('/conversations', [WidgetRelayController::class, 'store'])->name('store');
    Route::get('/conversations/{conversation}', [WidgetRelayController::class, 'show'])->name('show')->whereUlid('conversation');
    Route::post('/conversations/{conversation}/messages', [WidgetRelayController::class, 'message'])->name('message')->whereUlid('conversation');
    Route::post('/conversations/{conversation}/handoff', [WidgetRelayController::class, 'handoff'])->name('handoff')->whereUlid('conversation');
    Route::post('/conversations/{conversation}/close', [WidgetRelayController::class, 'close'])->name('close')->whereUlid('conversation');
    Route::post('/conversations/{conversation}/rating', [WidgetRelayController::class, 'rate'])->name('rating')->whereUlid('conversation');
    Route::post('/help/{node}/feedback', [WidgetRelayController::class, 'feedback'])->name('help.feedback')->whereUlid('node');
});
```

## Comandos

| Comando                          | O que faz                                                                          |
| -------------------------------- | ---------------------------------------------------------------------------------- |
| `php artisan rastro:help-guide`  | Cria o `RASTRO-AJUDA.md`, o guia para a sua IA escrever a árvore. `--force` troca pela versão do pacote |
| `php artisan rastro:help-push`   | Manda o `rastro-ajuda.json` para a central, como rascunho                          |
| `php artisan rastro:scan`        | Lê o projeto e manda para a IA do Rastro montar a árvore. `--show` só mostra; `--locale=` escolhe o idioma |

## Atualizar o pacote

```bash
composer update callcocam/rastro-laravel
```

Com a tag Blade ou o plugin do Filament, é só isso: o chat novo já vai para os navegadores.

Se você publicou o `RastroWidget.vue`, publique de novo e compile:

```bash
php artisan vendor:publish --tag=rastro-widget --force
npm run build
```

O `--force` troca o arquivo publicado pela versão nova, com as novidades, as correções e os filtros de privacidade. Por isso as cores ficam nas variáveis CSS, e não no arquivo.

## Problemas comuns

| O que acontece                                      | O que fazer                                                                           |
| --------------------------------------------------- | ------------------------------------------------------------------------------------- |
| O botão do chat não aparece                         | Confira se a tag, o plugin ou o `<RastroWidget />` está no layout certo. Com o componente Vue, rode `npm run build`. Com a tag, abra `/support/widget/rastro-widget.js` no navegador: ele deve carregar |
| O chat aparece em um painel do Filament e não em outro | Registre o `RastroPlugin` em cada painel                                        |
| O navegador bloqueia o script                       | Se o sistema tem Content Security Policy, libere scripts do próprio domínio (`script-src 'self'`) |
| O chat diz "O suporte está indisponível agora"      | Confira `RASTRO_URL` e `RASTRO_KEY` e rode `php artisan config:clear`. Se a central recusar a chave, o log mostra `rastro: the central refused the key (RASTRO_KEY)`. Se a chave foi apagada no painel, crie outra |
| Erro 419 ao mandar mensagem                         | A sessão expirou ou o cookie `XSRF-TOKEN` não chega. Confira se as rotas do chat estão no grupo `web` |
| Erro 404 em `/support/widget/config`                | As rotas não foram registradas: confira `RASTRO_ROUTES`, `RASTRO_WIDGET_PREFIX`, `RASTRO_WIDGET_DOMAIN` e rode `php artisan route:clear` |
| As cores do painel não mudam o chat                 | As variáveis `--wsw-*` do seu CSS valem mais do que o painel                          |
| `rastro:scan` ou `rastro:help-push` não conseguem enviar | Confira `RASTRO_URL` e `RASTRO_KEY` no servidor onde o comando roda              |

Mais ajuda: https://rastro.support/ajuda, ou escreva para contato@rastro.support.

## Para agentes de IA

O pacote traz orientações para o [Laravel Boost](https://github.com/laravel/boost) (`resources/boost`): onde o chat entra em cada tipo de sistema, a regra de não editar o chat nem copiar o `dist/rastro-widget.js` para o projeto, e a skill `rastro-widget-theme`, para ajustar as cores ao sistema. Um agente que instala o chat deve seguir o guia https://rastro.support/ajuda/instalar-o-chat, e um que muda a aparência deve seguir o [CUSTOMIZING.md](CUSTOMIZING.md).

## Desenvolvimento do pacote

```bash
composer install
composer test        # Pint, PHPStan e Pest
composer lint        # formata com o Pint
npm install
npm run build        # gera dist/rastro-widget.js depois de mudar RastroWidget.vue ou embed.ts
```

O `dist/rastro-widget.js` vai no Git, para o `composer require` bastar no sistema do cliente.

## Licença

MIT. Veja [LICENSE](LICENSE).
