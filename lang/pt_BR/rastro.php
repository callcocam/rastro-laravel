<?php

return [
    'unavailable' => 'O suporte está indisponível agora. Tente de novo em instantes.',
    'scan_sent' => 'Leitura enviada para a central. Os rascunhos aparecem na Árvore de dúvidas em alguns minutos.',
    'scan_sent_no_ai' => 'Leitura enviada para a central, mas a IA não está disponível no seu plano agora.',
    'scan_failed' => 'Não consegui enviar para a central. Confira RASTRO_URL e RASTRO_KEY.',
    'guide_created' => 'Criei o :file na raiz do projeto.',
    'guide_exists' => 'O :file já existe. Use --force para trocar pela versão deste pacote.',
    'guide_next' => 'Agora peça ao seu agente de código: "Siga o :file". Ele vai escrever o :output; depois de revisar, rode php artisan rastro:help-push.',
    'push_missing' => 'Não achei :file. Gere o guia com php artisan :guide e peça ao seu agente de código para escrever a árvore.',
    'push_invalid' => ':file não é um JSON válido com a lista "topics".',
    'push_refused' => 'A central recusou o arquivo. Corrija e mande de novo:',
    'push_done' => 'Árvore enviada: :created novas, :updated rascunhos atualizados, :kept já publicadas ficaram como estavam. Revise e publique na Árvore de dúvidas da central.',
];
