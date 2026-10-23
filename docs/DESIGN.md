# ThreadSafe design: colours and type

The look of the web interface (`public/app.css`) and of the restore wizard (`src/Web/Html.php`, one file with its style inline). It follows the palette of [DESIGN-theLoom.md](DESIGN-theLoom.md): a "workshop panel" - charcoal surfaces, **one** accent (brass), cool blue-grey text, monospace for labels. That sheet covers a dark theme only; this page says what was added: a **light theme**, the **status colours**, and a few tokens for controls. The two themes follow the system (`prefers-color-scheme`); there is no switch in the interface.

**Keep the two stylesheets in step.** `public/app.css` and `Html::css()` define the same tokens with the same values (the wizard in a shorter form). A change of a colour is made in both.

## Tokens

Names are those of the-loom (`--ink`, `--panel`, `--brass` ...) plus the additions marked *new*.

| Token | Dark | Light | Role |
|---|---|---|---|
| `--ink` | `#14191f` | `#eef0f2` | page background |
| `--panel` | `#1b232c` | `#ffffff` | header, boxes, tables, dialogs |
| `--panel-2` | `#212a33` | `#f6f7f8` | cards, messages, secondary buttons |
| `--recess` | `#0f1318` | `#e5e8eb` | inset: form fields, code, the progress bar |
| `--border` | `#303b47` | `#cdd3d9` | 1px lines; separates, carries no information |
| `--border-strong` *new* | `#667380` | `#7f8a95` | the boundary of a control (form field, secondary button) |
| `--text` | `#e9ebe8` | `#14191f` | headings, main text |
| `--text-soft` | `#9aa2a8` | `#434e58` | running copy, hints, navigation |
| `--text-faint` | `#838b92` | `#56616b` | table headings, footer |
| `--brass` | `#e0a13d` | `#8a5a08` | links, the focus ring, the current navigation entry - the accent **as text** |
| `--brass-bright` | `#f0b75a` | `#6b4506` | hover of a link |
| `--brass-fill` *new* | `#e0a13d` | `#e0a13d` | the primary button, the progress bar, the current step |
| `--brass-fill-hover` *new* | `#f0b75a` | `#f0b75a` | hover of the fill |
| `--brass-edge` *new* | `#e0a13d` | `#a8740f` | the edge of a brass fill (on white it needs one) |
| `--on-brass` *new* | `#14191f` | `#14191f` | text on the brass fill |
| `--ok` *new* | `#5cb87a` | `#1a7339` | success |
| `--warning` *new* | `#e6c84f` | `#8f4a00` | something to read |
| `--error` *new* | `#e87065` | `#b3382c` | failure, a problem |
| `--danger-fill`, `--on-danger` *new* | `#e87065`, `#14191f` | `#b3382c`, `#ffffff` | the button that deletes |

The dark column is the-loom's own (`--ok` is its `#5cb87a`; `--error` is its `#e2685c` made a little lighter so that it keeps 4.5:1 on a card). The light column is derived: the surfaces are the dark panel turned inside out (cool greys, white boxes, a darker inset), the text is the dark panel's colour, and brass is **two colours**, because the brass of the dark theme is 2.2:1 on white: the **fill** of the primary button stays brass (dark text on it is 7.9:1), the **text** of links and focus rings is a dark brass.

**Status colours.** Green for success, a yellow (dark) or burnt orange (light) for warnings, red for errors. A warning is kept away from the brass of the accent by hue and by use: brass is interactive (links, buttons, the focus ring), a status colour is never a link. Colour is never the only carrier: every status has a word ("OK", "Warning", "Problem", "faulty", "incomplete"), and messages and cards have a coloured **left edge** plus text in the normal text colour, not coloured text.

## Contrast (WCAG)

Text on the surfaces it is used on; everything readable is at least 4.5:1.

| Text | Dark: ink / panel / panel-2 | Light: ink / panel / panel-2 |
|---|---|---|
| `--text` | 14.7 / 13.2 / 12.1 | 15.5 / 17.7 / 16.5 |
| `--text-soft` | 6.8 / 6.1 / 5.6 | 7.4 / 8.5 / 7.9 |
| `--text-faint` | 5.1 / 4.6 / **4.2** | 5.5 / 6.3 / 5.9 |
| `--brass` (links) | 7.9 / 7.1 / 6.5 | 5.2 / 5.9 / 5.5 |
| `--ok` | 7.2 / 6.5 / 6.0 | 5.2 / 5.9 / 5.5 |
| `--warning` | 10.7 / 9.6 / 8.8 | 5.8 / 6.7 / 6.2 |
| `--error` | 5.8 / 5.2 / 4.8 | 5.2 / 6.0 / 5.6 |

Other pairs: `--on-brass` on `--brass-fill` 7.9; `--on-danger` on `--danger-fill` 5.8 (dark) and 6.0 (light); `--border-strong` against the surfaces 3.0 to 3.6 (dark) and 3.1 to 3.5 (light), the 3:1 that the boundary of a control needs; `--text` on `--recess` 15.5 and 14.4.

Rules that keep it so: `--text-faint` only on `--ink`, `--panel` and `--recess`, not on `--panel-2` in the dark theme (4.2); never dim text with `opacity`; `--border` is deliberately low (1.4 to 1.5:1) and carries no information.

## Type

Two families, as in the-loom: **IBM Plex Sans** for prose, headings and form labels, **IBM Plex Mono** for anything that reads like an instrument label - the wordmark, the navigation, buttons, table headings, badges, the steps of the wizard, the footer, code - small, uppercase and letter-spaced where it is a label.

The stacks are `"IBM Plex Sans", ui-sans-serif, system-ui, ...` and `"IBM Plex Mono", ui-monospace, "Cascadia Mono", Consolas, ...`: Plex is used where it is installed, otherwise the system fonts. **No font file is loaded**: the interface sends no request to another server (the content security policy allows only its own files, and a backup tool should not tell a font service that it was opened). To show Plex everywhere, self-host the WOFF2 files below `public/` and add `@font-face` rules (the fonts are under the SIL Open Font License).

Sizes: body 1rem / 1.55; `h1` 1.375rem; `h2` 1.15rem; labels in mono 0.7 to 0.78rem.

## Components

- **Primary button:** `--brass-fill` with `--on-brass` text, 1px `--brass-edge`, mono uppercase, radius 6px; hover `--brass-fill-hover`. **Secondary:** `--panel-2`, 1px `--border-strong`, `--text`; hover edge brass. **Danger:** `--danger-fill`. A link that looks like text (`button.link`) is a brass underlined link.
- **Links:** brass and underlined (they are in running text, tables and lists); the navigation, the wordmark and the buttons are not underlined. The focus ring is 2px brass with a 2px offset, on every control; it is never removed.
- **Form fields:** `--recess` fill, 1px `--border-strong`, radius 6px; brass edge on focus.
- **Box, card:** `--panel` (box) or `--panel-2` (card) with a 1px `--border` and radius 8px; no shadow, no gradient. A card and a message have a 4 to 5px coloured left edge for the status.
- **Badge:** mono uppercase 0.68rem, the status colour as text, a 12 % tint of it as fill and a 45 % one as edge (`color-mix`).
- **Table:** `--panel`, 1px `--border` rules, headings in mono uppercase `--text-faint`.
- **Code and key blocks:** `--recess` with a 1px `--border`, mono, radius 4px.
- **Print:** the page that shows a new key prints black on white and only the key; its rules are in the `@media print` block of `app.css`.

## Where it is used

`public/app.css` (all pages of the interface), `src/Web/Html.php` (`css()`, the restore wizard). The tests do not look at colours; contrast was computed from the values above with the formula of WCAG 2.x and the pages were looked at in both themes at desktop width and at 375 px.
