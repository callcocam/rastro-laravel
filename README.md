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

1. O chat (`RastroWidget.vue`) fala **só com o seu próprio sistema**, no mesmo domínio. A sessão e o login do cliente vão juntos, e não precisa de CORS.
2. O seu sistema repassa a conversa para a central do Rastro, de servidor para servidor, com a chave do site (`RASTRO_KEY`). **A chave nunca vai para o navegador.**
3. Junto com cada mensagem vão quem está logado (nome, e-mail e id), o rastro do navegador e o rastro do servidor.
4. O pacote **não cria tabelas nem telas**. Ele não guarda as conversas: elas ficam no Rastro.

## Em quais sistemas funciona

| Sistema                                      | Situação      |
| -------------------------------------------- | ------------- |
| Laravel com Inertia e Vue 3                  | Pronto        |
| Laravel com Blade, Livewire ou Inertia React | Em breve      |
| Painel Filament (v3 e v4)                    | Em breve      |
| Site sem Laravel (só JavaScript)             | Em breve      |

## Requisitos

- PHP 8.3 ou mais novo
- Laravel 11, 12 ou 13
- Para o chat: Inertia com Vue 3, compilado pelo Vite do seu projeto
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

### 4. Publique o chat e coloque no layout

```bash
php artisan vendor:publish --tag=rastro-widget
```

Isso cria `resources/js/components/rastro/RastroWidget.vue`. Coloque o chat **uma vez só**, no layout das telas onde ele deve aparecer:

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

> Não edite o `RastroWidget.vue` publicado. Para mudar as cores, use as variáveis CSS (veja [Aparência](#aparência)). Assim, quando o pacote for atualizado, basta publicar de novo (veja [Atualizar o pacote](#atualizar-o-pacote)).

### 5. Compile e teste

```bash
php artisan config:clear   # se o projeto usa cache de configuração
npm run build
```

Abra o sistema. O botão do chat aparece no canto da tela. Mande uma mensagem de teste: ela chega na caixa de entrada do Rastro. Se a pessoa estiver logada, o nome e o e-mail já vêm preenchidos.

## Exemplos

### Nas telas logadas e no site público

Se o sistema tem mais de um layout, coloque `<RastroWidget />` em cada um em que o chat deve aparecer. A conversa continua entre as telas e depois de recarregar a página, porque fica guardada no navegador.

```vue
<!-- resources/js/layouts/AuthLayout.vue (login, cadastro, esqueci a senha) -->
<script setup lang="ts">
import RastroWidget from '@/components/rastro/RastroWidget.vue';
</script>

<template>
    <main>
        <slot />
        <RastroWidget />
    </main>
</template>
```

### Só para quem está logado

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

### Outro endereço para o chat

Por padrão, o chat conversa com o seu sistema em `/support/widget`. Se esse endereço já está em uso:

```dotenv
RASTRO_WIDGET_PREFIX=ajuda/chat
```

```vue
<RastroWidget endpoint="/ajuda/chat" />
```

### Dois sistemas no mesmo domínio

A conversa fica guardada no navegador com a chave `rastro`. Se dois sistemas seus rodam no mesmo domínio, dê um nome diferente para cada um, para as conversas não se misturarem:

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
| GET    | `/config`                                 | `rastro.widget.config`        |
| POST   | `/conversations`                          | `rastro.widget.store`         |
| GET    | `/conversations/{conversation}`           | `rastro.widget.show`          |
| POST   | `/conversations/{conversation}/messages`  | `rastro.widget.message`       |
| POST   | `/conversations/{conversation}/handoff`   | `rastro.widget.handoff`       |
| POST   | `/conversations/{conversation}/close`     | `rastro.widget.close`         |
| POST   | `/conversations/{conversation}/rating`    | `rastro.widget.rating`        |
| POST   | `/help/{node}/feedback`                   | `rastro.widget.help.feedback` |

O chat manda o token CSRF (`X-XSRF-TOKEN`) como qualquer requisição do Laravel, então nada precisa sair da proteção de CSRF. Cada conversa é protegida por um token próprio (`X-Support-Token`), guardado no navegador de quem abriu a conversa.

Para registrar as rotas você mesmo, por exemplo com outro middleware:

```php
// routes/web.php
use Callcocam\Rastro\Http\WidgetRelayController;

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
php artisan vendor:publish --tag=rastro-widget --force
npm run build
```

O `--force` troca o `RastroWidget.vue` publicado pela versão nova: novidades, correções e os filtros de privacidade. Por isso as cores ficam nas variáveis CSS, e não no arquivo.

## Problemas comuns

| O que acontece                                      | O que fazer                                                                           |
| --------------------------------------------------- | ------------------------------------------------------------------------------------- |
| O botão do chat não aparece                         | Confira se `<RastroWidget />` está no layout certo e se você rodou `npm run build`    |
| O chat diz "O suporte está indisponível agora"      | Confira `RASTRO_URL` e `RASTRO_KEY` e rode `php artisan config:clear`. Se a central recusar a chave, o log mostra `rastro: the central refused the key (RASTRO_KEY)`. Se a chave foi apagada no painel, crie outra |
| Erro 419 ao mandar mensagem                         | A sessão expirou ou o cookie `XSRF-TOKEN` não chega. Confira se as rotas do chat estão no grupo `web` |
| Erro 404 em `/support/widget/config`                | As rotas não foram registradas: confira `RASTRO_ROUTES`, `RASTRO_WIDGET_PREFIX`, `RASTRO_WIDGET_DOMAIN` e rode `php artisan route:clear` |
| As cores do painel não mudam o chat                 | As variáveis `--wsw-*` do seu CSS valem mais do que o painel                          |
| `rastro:scan` ou `rastro:help-push` não conseguem enviar | Confira `RASTRO_URL` e `RASTRO_KEY` no servidor onde o comando roda              |

Mais ajuda: https://rastro.support/ajuda, ou escreva para contato@rastro.support.

## Para agentes de IA

O pacote traz orientações para o [Laravel Boost](https://github.com/laravel/boost) (`resources/boost`): a regra de não editar o chat e a skill `rastro-widget-theme`, para ajustar as cores ao sistema. Um agente que instala o chat deve seguir o guia https://rastro.support/ajuda/instalar-o-chat, e um que muda a aparência deve seguir o [CUSTOMIZING.md](CUSTOMIZING.md).

## Desenvolvimento do pacote

```bash
composer install
composer test        # Pint, PHPStan e Pest
composer lint        # formata com o Pint
```

## Licença

MIT. Veja [LICENSE](LICENSE).
