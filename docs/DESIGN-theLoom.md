# the-loom — colour & type spec

Hand-off sheet for anything that should look like it belongs to the-loom.de
(product sites, extension landing pages, graphics, docs). Source of truth is
`templates/the-loom/css/template.css`; this file mirrors its `:root` block.

## Identity in one paragraph

"Werkstattpanel": a dark instrument panel. Charcoal surfaces, **one** accent
(brass), cool blue-grey text, engineered monospace for labels and readouts.
Single theme, **dark only — no light mode.** Calm and technical, not glossy:
no gradients, no shadows, no second accent colour.

## Colour tokens

| Token | Hex | Role |
|---|---|---|
| `--recess` | `#0f1318` | Inset surfaces: odometer readouts, form fields, bank grids |
| `--ink` | `#14191f` | Page background |
| `--panel` | `#1b232c` | Raised surfaces: header, footer, content boxes |
| `--panel-2` | `#212a33` | Cards, one step above `--panel` |
| `--border` | `#303b47` | 1px borders and dividers |
| `--brass` | `#e0a13d` | The accent: links, version digits, focus ring, primary button, logo |
| `--brass-bright` | `#f0b75a` | Hover state of brass; "current" version |
| `--text` | `#e9ebe8` | Headings, main text |
| `--text-soft` | `#9aa2a8` | Body copy, menu, descriptions |
| `--text-faint` | `#838b92` | Labels, captions, footer, form labels |

Contrast (WCAG), text on `--ink` / `--panel`: `--text` 14.7 / 13.2,
`--text-soft` 6.8 / 6.1, `--text-faint` 5.1 / 4.6, `--brass` 7.9 / 7.1.
Everything readable is ≥ 4.5:1 — keep it that way. Don't use `--text-faint`
on anything lighter than `--panel`, and don't dim text with `opacity` (it
fades contrast below AA); pick a token instead. `--border` is deliberately
low-contrast (≈1.4–1.6:1): it separates, it carries no information.

Minor one-offs: card hover fill `#2b3540`; muted brass badge = border
`#4a3a20` on `rgba(224,161,61,0.1)`; success `#5cb87a`; error `#e2685c`.

```css
:root {
  --ink: #14191f;
  --panel: #1b232c;
  --panel-2: #212a33;
  --recess: #0f1318;
  --border: #303b47;
  --brass: #e0a13d;
  --brass-bright: #f0b75a;
  --text: #e9ebe8;
  --text-soft: #9aa2a8;
  --text-faint: #838b92;
  --radius: 8px;
}
body { background: var(--ink); color: var(--text); line-height: 1.55; }
```

## Typography

Two families, both IBM Plex.

| Family | Weights | Used for |
|---|---|---|
| **IBM Plex Sans** | 400, 500, 600, 700 | Body, headings (600), card titles, form fields |
| **IBM Plex Mono** | 400, 500, 600 | Wordmark, navigation, labels/eyebrows, version odometer, release log, buttons, footer |

Stacks: `"IBM Plex Sans", ui-sans-serif, system-ui, sans-serif` and
`"IBM Plex Mono", ui-monospace, monospace`.

Rule of thumb: **prose is Sans, anything that reads like an instrument label
or readout is Mono, small and uppercase with tracking.**

| Element | Family | Size | Notes |
|---|---|---|---|
| Body | Sans | 1rem / line-height 1.55 | `--text-soft` for running copy |
| Headings | Sans 600 | h1 ≈ 1.375rem, h2 1.3rem | line-height 1.25, `text-wrap: balance`, colour `--text` |
| Card title | Sans 600 | 0.98rem | |
| Card description | Sans | 0.82rem | `--text-soft` |
| Wordmark | Mono 600 | 0.85rem | uppercase, letter-spacing 0.2em |
| Nav, buttons | Mono | 0.72–0.78rem | uppercase, letter-spacing 0.06em |
| Eyebrow / bank label | Mono | 0.7rem | uppercase, letter-spacing 0.14em, `--text-faint` |
| Odometer, badges | Mono | 0.65–0.72rem | digits `--brass` on `--recess`, last segment `--text-faint` |
| Footer | Mono | 0.72rem | letter-spacing 0.04em |

```html
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap">
```

Currently loaded from the Google Fonts CDN. For a German-hosted site,
self-hosting the WOFF2 files is the privacy-safe route (a CDN request sends
the visitor's IP to Google) — prefer that for anything new.

## Component conventions

- **Card:** `--panel-2` fill, 1px `--border`, radius `--radius` (8px). No shadow.
- **Inset readout (odometer):** `--recess` fill, 1px `--border`, radius 4px,
  mono, segments divided by 1px borders.
- **Links:** `--brass`, no underline, `--brass-bright` + underline on hover.
  Links *inside running text* are always underlined (not colour alone).
- **Focus:** 2px solid `--brass` outline, 2px offset — never remove it.
- **Primary button:** `--brass` fill, `--ink` text, mono uppercase, radius 6px,
  hover `--brass-bright`.
- **Form fields:** `--recess` fill, `--border`, `--text`, radius 6px,
  brass border + outline on focus. Labels mono uppercase `--text-faint`.
- **Icons:** 24×24 viewBox, `stroke="currentColor"`, ~1.5 stroke width,
  round caps/joins, no fill — they inherit colour from context (usually brass).
- **Spacing/shape:** one radius (8px cards, 4–6px small controls), 1px lines,
  generous whitespace, content column ≤ 1100px.

## Logo

Lambda mark with a detached "extension" stroke, flat `#e0a13d` (= `--brass`),
transparent background, works on `--ink`, `--panel` and white.
Source: `templates/the-loom/images/favicon.svg` (also `.design/mark.svg`).
Header lockup: mark (≈26px wide) + wordmark "THE LOOM".
