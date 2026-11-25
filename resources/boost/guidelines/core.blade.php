## Rastro chat (callcocam/rastro-laravel)

- The support chat is `RastroWidget.vue`, from this package. To change how it looks, set the `--wsw-*` CSS variables in the site's own CSS (`:root` and `.dark`); never publish or edit the component, or the site stops getting the chat's updates and privacy fixes.
- Colors: the site's `--wsw-*` win over the color chosen in the company's Rastro panel ("Chat do site"), which wins over the defaults.
- Full guide, with every variable and a checklist: `vendor/callcocam/rastro-laravel/CUSTOMIZING.md`. Use the `rastro-widget-theme` skill when asked to style or theme the chat.
