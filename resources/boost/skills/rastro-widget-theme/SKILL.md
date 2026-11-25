---
name: rastro-widget-theme
description: Style the Rastro support chat (RastroWidget.vue from callcocam/rastro-laravel) to match the site, light and dark, through its --wsw-* CSS variables. Use when asked to theme, recolor, brand or "make the chat look like our system".
---

# Theme the Rastro chat

Read `vendor/callcocam/rastro-laravel/CUSTOMIZING.md` first and follow it.

1. Do not publish or edit `RastroWidget.vue`. Only set `--wsw-*` CSS variables in the site's CSS.
2. Find the site's palette (Tailwind `@theme` / config, design-system custom properties) for light and dark.
3. Map it: `--wsw-accent`, `--wsw-accent-text`, `--wsw-mine`, `--wsw-bg`, `--wsw-panel`, `--wsw-border`, `--wsw-text`, `--wsw-muted`, `--wsw-danger`. Keep text on the accent at 4.5:1 contrast or more.
4. Write them on `:root` and on the site's dark selector (usually `.dark`), preferably pointing at the site's own tokens (`var(--color-primary)`).
5. Rebuild the front end and check a page with the chat open, in light and dark.
6. Report which variables changed and where. Variables set in CSS override the color picked in the Rastro panel.
