# Customizing the Rastro chat

This guide is for the developer, or the AI coding agent, of a site that
uses `callcocam/rastro-laravel`. It says how to change the look of the chat
(`RastroWidget.vue`) **without forking it**.

## The rules

1. **Do not publish or edit `RastroWidget.vue`** (`vendor:publish --tag=rastro-widget`).
   A published copy stops getting updates: new features, fixes and the
   privacy filters that keep passwords and personal data out of the trail.
   Only do it if the owner asks for it and accepts that.
2. **Change the look only with the `--wsw-*` CSS variables**, in the site's
   own CSS (for example `resources/css/app.css`).
3. Do not override the widget's classes (`.wsw-*`) or its layout. Its
   structure can change between versions; the variables are the contract.

## Where colors come from

From highest priority to lowest:

1. **The site's CSS:** the `--wsw-*` variables below, set on `:root`, on
   `.dark`, or on any parent of the chat.
2. **The company's panel** on Rastro ("Chat do site"): main color, corner
   (right or left) and light, dark or "same as the site". It reaches the
   chat with its config, within a minute, with no deploy.
3. **The chat's defaults** (blue, light, follows the site's `dark` class).

So a site that sets `--wsw-accent` in CSS keeps that color, whatever is
picked in the panel. Set only what you need; the rest falls back.

## The variables

| Variable | What it paints |
| --- | --- |
| `--wsw-accent` | Launcher button, header, main buttons, links |
| `--wsw-accent-text` | Text and icons on `--wsw-accent` (pick for contrast) |
| `--wsw-mine` | The visitor's message bubbles (default: a light tint of the accent) |
| `--wsw-bg` | The chat panel's background |
| `--wsw-panel` | The conversation area and fields' background |
| `--wsw-border` | Borders and dividers |
| `--wsw-text` | Main text |
| `--wsw-muted` | Secondary text (hints, times) |
| `--wsw-danger` | Errors and the unread badge |
| `--wsw-bottom` | Distance from the bottom of the screen (default `16px`, `12px` on phones) |
| `--wsw-side` | Distance from the right (or left) edge (default `16px`, `12px` on phones) |

On screens up to 640px wide the launcher is only a round 48px icon, so it
covers less of the page. If it still sits on top of a bar of the site (a
fixed "Save" footer, a bottom menu), lift it on phones only:

```css
@media (max-width: 640px) {
    :root {
        --wsw-bottom: 80px;
    }
}
```

## Example: match the site's colors (light and dark)

```css
/* resources/css/app.css */
:root {
    --wsw-accent: #e11d48;
    --wsw-accent-text: #ffffff;
    --wsw-bg: #ffffff;
    --wsw-panel: #f8fafc;
    --wsw-border: #e2e8f0;
    --wsw-text: #0f172a;
    --wsw-muted: #64748b;
}

.dark {
    --wsw-accent: #fb7185;
    --wsw-accent-text: #1c0a10;
    --wsw-bg: #0b1120;
    --wsw-panel: #111827;
    --wsw-border: #1f2937;
    --wsw-text: #f1f5f9;
    --wsw-muted: #94a3b8;
}
```

With Tailwind v4 you can point them at the theme instead of repeating hex
values: `--wsw-accent: var(--color-primary);` (use the site's real token
names).

## Checklist for an AI agent

1. Find the site's palette: the Tailwind theme (`@theme` in the CSS, or
   `tailwind.config.*`) and the CSS custom properties of the design system,
   for light and dark.
2. Map them to the variables above. Keep `--wsw-accent-text` readable on
   `--wsw-accent` (WCAG contrast at least 4.5:1).
3. Write them in the site's main CSS file, on `:root` and `.dark` (or the
   site's dark selector). Do not touch the package's files.
4. Rebuild the front end (`npm run build`) and open a page with the chat to
   check it, in light and dark.
5. Tell the owner which variables changed and in which file, and that the
   panel's color no longer applies to the ones set in CSS.
