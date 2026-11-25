# Guia: árvore de dúvidas do Rastro

Este arquivo é para o **agente de código** (Claude Code, Cursor, Copilot…) que trabalha neste projeto. Ele foi criado por `php artisan rastro:help-guide`, do pacote `callcocam/rastro-laravel`.

Quem pede: a equipe deste sistema. Ela usa o **Rastro** (https://rastro.support) para atender os clientes pelo chat do site, pelo WhatsApp e por e-mail. Lá existe a **árvore de dúvidas**: as opções que o chat mostra antes de chamar uma pessoa. A **IA do atendimento responde só com o que está nessa árvore**, então uma árvore boa faz a IA resolver sozinha as dúvidas comuns. A IA não lê este código, só a árvore.

## O que fazer

1. **Leia o projeto para entender o produto do ponto de vista do cliente final:** as telas (páginas e componentes), as rotas, as regras de validação, os textos das traduções (`lang/`), as mensagens de erro, os e-mails enviados, os planos e as cobranças. Leia também a documentação que existir (`README`, `docs/`, páginas de ajuda, FAQ, termos).
2. **Liste as dúvidas que um cliente teria:** criar conta e entrar, senha, perfil, as funções principais, erros comuns e o que fazer, planos e pagamento, cancelamento, privacidade e contato.
3. **Escreva a árvore no arquivo `rastro-ajuda.json`, na raiz do projeto**, no formato abaixo.
4. **Confira** se o JSON é válido e se segue as regras.
5. **Avise a pessoa** para revisar o arquivo e rodar `php artisan rastro:help-push`. O comando manda a árvore para o Rastro, onde tudo entra como **rascunho**. A equipe revisa e publica na tela "Árvore de dúvidas" da central; nada aparece para os clientes antes disso.

## Como escrever

- **Para o cliente final, não para programador.** Use os nomes que aparecem na tela (botões, menus, títulos), nunca nomes de classe, tabela, rota ou campo.
- **Respostas curtas e práticas:** passo a passo numerado quando for um procedimento, de 2 a 8 linhas. Escreva como um bom atendente.
- **Só o que o código ou a documentação confirmam.** Não invente prazo, preço, regra, função ou contato. Se algo depende de configuração que você não enxerga, por exemplo o valor de um plano, escreva de forma genérica ("veja os valores na tela Assinatura") ou deixe de fora.
- **Nada interno:** sem chaves, senhas, endereços de servidor, nomes de tabela, painéis administrativos nem dados de clientes.
- **Links:** use `help_url` quando houver uma página pública de ajuda, artigo ou vídeo sobre o assunto, sempre com o endereço completo (`https://...`).
- **Use o idioma do sistema** (o mesmo das telas).

## Formato do `rastro-ajuda.json`

```json
{
  "topics": [
    {
      "label": "Acesso e senha",
      "prompt": "Qual é a sua dúvida sobre acesso?",
      "children": [
        {
          "label": "Esqueci minha senha",
          "answer": "1. Na tela de entrada, clique em \"Esqueci minha senha\".\n2. Digite o seu e-mail e clique em \"Enviar\".\n3. Abra o e-mail que chegou e escolha a nova senha.\nSe o e-mail não chegar em alguns minutos, olhe a caixa de spam.",
          "help_url": "https://exemplo.com.br/ajuda/senha"
        }
      ]
    },
    {
      "label": "Planos e pagamento",
      "answer": "Os planos e a forma de pagamento ficam na tela Assinatura, no menu da sua conta."
    }
  ]
}
```

Cada opção tem:

| Campo | Obrigatório | Limite | O que é |
|---|---|---|---|
| `label` | sim | 120 caracteres | O texto do botão, curto ("Esqueci minha senha") |
| `prompt` | não | 255 caracteres | A pergunta mostrada antes das opções de dentro (só em quem tem `children`) |
| `answer` | quando não houver `children` nem `help_url` | 10.000 caracteres | A resposta. Quebra de linha com `\n` |
| `help_url` | não | 500 caracteres | Link completo (`https://...`) para a página de ajuda ou o vídeo |
| `children` | não | — | As opções de dentro |

Regras da central (um arquivo fora delas é recusado, com o caminho de cada problema):

- até **3 níveis**: assunto › dúvida › detalhe; o comum é usar 2;
- até **500 opções** no total; o bom é de **4 a 8 assuntos** no primeiro nível, cada um com **3 a 10 dúvidas**;
- toda opção sem `children` precisa de `answer` ou de `help_url`;
- o `label` identifica a opção: mandar o arquivo de novo **atualiza** os rascunhos com o mesmo nome no mesmo lugar e **não mexe** no que a equipe já publicou. Então mantenha os nomes estáveis quando for refazer.

## Quando terminar

Diga à pessoa, em poucas linhas:

- quantos assuntos e dúvidas escreveu;
- o que ficou de fora por falta de informação (para ela completar);
- o próximo passo: revisar `rastro-ajuda.json`, rodar `php artisan rastro:help-push` e publicar os rascunhos na "Árvore de dúvidas" do Rastro.
