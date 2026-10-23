# ThreadSafe design: colours and type

The look of the web interface (`public/app.css`) and of the restore wizard (`src/Web/Html.php`, one file with its style inline). It follows the palette of [DESIGN-theLoom.md](DESIGN-theLoom.md): a "workshop panel" - charcoal surfaces, **one** accent (brass), cool blue-grey text, monospace for labels. That sheet covers a dark theme only; this page says what was added: a **light theme**, the **status colours**, a few tokens for controls, the **menu on the left** and the **switch for the colour scheme**.

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
| `--border-strong` *new* | `#6a7784` | `#7f8a95` | the boundary of a control (form field, secondary button) |
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

Other pairs: `--on-brass` on `--brass-fill` 7.9; `--on-danger` on `--danger-fill` 5.8 (dark) and 6.0 (light); `--border-strong` against the surfaces 3.2 to 3.9 (dark) and 3.1 to 3.5 (light), the 3:1 that the boundary of a control needs; `--text` on `--recess` 15.5 and 14.4.

Rules that keep it so: `--text-faint` only on `--ink`, `--panel` and `--recess`, not on `--panel-2` in the dark theme (4.2); never dim text with `opacity`; `--border` is deliberately low (1.4 to 1.5:1) and carries no information.

## Layout and the switch

- **The menu** is a panel on the left (`--panel`, 14.5rem, sticky, full height): at the top the mark - a brass tile with **TS** in mono, until there is a logo - and the wordmark; directly under it the **fold button** (an arrow and *Fold the menu*, above the entries); then one entry per page with an **icon and a name**; at the bottom the switch for the colour scheme and **Log out**. The current page has `--panel-2` and a 2px brass edge on the left. **Folded**, the menu is 3.75rem wide and shows the icons only (the names stay in the page for screen readers and in the tooltips); on a screen narrower than 40rem it is always folded and the fold button is hidden. The pages before the login have no menu: a bar on top with the mark and the switch.
- **Icons** are inline SVG, 24 x 24, a line of 1.5 in the colour of the text around them, round ends, no fill (`src/Admin/Icons.php`); they stand next to a name or inside a button that has one.
- **The switch** has three positions - **Auto** (monitor), **Light** (sun), **Dark** (moon) - as three buttons in a recessed group; the pressed one is brass (`aria-pressed`). Auto follows `prefers-color-scheme`; Light and Dark set `data-theme` on the `html` element, which wins over the system. The script writes the choice into a cookie (`threadsafe_theme`; for the menu `threadsafe_nav`) and the server draws the page with it, so there is no flash and no script in the head (the content security policy forbids inline script). What needs script is hidden until the script has run (`.js-only`).
- The dark tokens are therefore in the stylesheet **twice**: once for the system's choice (`:root:not([data-theme="light"])` inside `prefers-color-scheme: dark`) and once for the switch (`:root[data-theme="dark"]`). Change both.

## Type

Two families, as in the-loom: **IBM Plex Sans** for prose, headings and form labels, **IBM Plex Mono** for anything that reads like an instrument label - the wordmark, the navigation, buttons, table headings, badges, the steps of the wizard, the footer, code - small, uppercase and letter-spaced where it is a label.

The fonts are **part of the program**: seven WOFF2 files (Sans 400, 500, 600, 700; Mono 400, 500, 600; the Latin set, which has the German umlauts and the usual punctuation) in `public/fonts/` with their license (`OFL.txt`, the SIL Open Font License), declared by `@font-face` in `app.css` (`font-display: swap`) and allowed by `font-src 'self'` in the content security policy. The interface sends no request to another server, and a backup tool does not tell a font service that it was opened. About 125 KB in all, cached by the browser. The stacks are `"IBM Plex Sans", ui-sans-serif, system-ui, ...` and `"IBM Plex Mono", ui-monospace, ...`.

**The restore wizard has no fonts of its own**: it is one file with nothing next to it and uses the system fonts of the stacks. (Embedding the fonts in `restore.php` would make the tool about 170 KB larger.)

Sizes: body 1rem / 1.55; `h1` 1.375rem; `h2` 1.15rem; labels in mono 0.7 to 0.78rem.

## Components

- **Primary button:** `--brass-fill` with `--on-brass` text, 1px `--brass-edge`, mono uppercase, radius 6px; hover `--brass-fill-hover`. **Secondary:** `--panel-2`, 1px `--border-strong`, `--text`; hover edge brass. **Danger:** `--danger-fill`. A link that looks like text (`button.link`) is a brass underlined link.
- **Links:** brass and underlined (they are in running text, tables and lists); the navigation, the wordmark and the buttons are not underlined. The focus ring is 2px brass with a 2px offset, on every control; it is never removed.
- **Form fields:** `--recess` fill, 1px `--border-strong`, radius 6px; brass edge on focus.
- **Box, card:** `--panel` (box) or `--panel-2` (card) with a 1px `--border` and radius 8px; no shadow, no gradient. A card and a message have a 4 to 5px coloured left edge for the status; a **message** (ok, warning, error) also has a faint tint of that colour as its background - 12 % over `--panel-2` - and its text stays in `--text` (the audit finds no text below 4.5:1 on it, in either scheme).
- **The card** (dashboard): `--panel-2`, the name in 1.15rem bold - text, not a link - with an **icon button** beside it, the state badge at the right, then at most the newest backup, the next backup and the warnings, and two buttons (the filled *Back up now*, the secondary *Profile*). The **icon button** is 2.25rem square with a 1.5rem icon (line 1.75), no label, in `--text` (brass on hover, with a `--border-strong` edge), with a tooltip (`title`) and an accessible name (`aria-label`); the pencil edits the profile.
- **The filter** above the tiles (website types): a row of chips - mono uppercase 0.75rem, `--panel` fill, 1px `--border-strong`, radius 6px, the number in bold - with a small label *Website type*; the chosen chip is brass like the pressed button of the switch (`aria-current="true"`); the chips are links.
- **The empty tile** (*New profile*, last in the grid of the dashboard): a card with a grey left edge (`--border-strong`; no status), a plus icon and the name in mono, centred; the whole surface is one link, and hover turns the edge brass.
- **Badge:** mono uppercase 0.68rem, the status colour as text, a 12 % tint of it as fill and a 45 % one as edge (`color-mix`).
- **Table:** `--panel`, 1px `--border` rules, headings in mono uppercase `--text-faint`.
- **Code and key blocks:** `--recess` with a 1px `--border`, mono, radius 4px.
- **Print:** the page that shows a new key prints black on white and only the key; its rules are in the `@media print` block of `app.css`.

## Accessibility

Checked against WCAG 2.2 level AA, in the light and in the dark scheme, with `tools/a11y-audit.js` (it composes the real background of every piece of text, layers and alpha included) and by hand:

| Criterion | How it is met | Result |
|---|---|---|
| 1.4.3 contrast of text (4.5:1, large text 3:1) | the tokens above; the audit looks at every text and every field of the pages | no text below 4.5:1 on the dashboard, the profile list, a profile, the profile form, the System page, the details of a backup, the result of a check, the keys page, the restore help, the login page and a sample page of the wizard - in both schemes |
| 1.4.11 contrast of controls (3:1) | `--border-strong` for fields, secondary buttons and the colour switch; the primary button has its own edge; the focus ring is brass | all controls at 3:1 or more |
| 1.4.1 colour is not the only carrier | every status has a word; the current page is marked by `aria-current`, a fill and an edge; the pressed button of the switch is marked by `aria-pressed` and a fill | as designed |
| 1.4.10 reflow | the content is one column, tables scroll inside their own box, the dashboard cards shrink | no sideways scrolling at 320 px on the pages tried |
| 1.3.1 / 4.1.2 structure and names | landmarks `aside` (named *Menu*), `nav` (*Main*), `main`, `footer`; one `h1`, no skipped heading level; every button, link and field has a name (the names of the icon buttons are in the page and hidden visually only); `aria-expanded`, `aria-controls`, `aria-pressed` on the fold button and the switch | the audit finds none missing |
| 2.4.1 bypass blocks | a link *Skip to the content* is the first thing the Tab key reaches and shows itself when it has the focus; `main` can take the focus | yes, also before the login |
| 2.4.7 focus visible | 2px brass outline with a 2px offset on every control, never removed | seen in both schemes on the skip link, the fold button, the entries and the switch |
| 2.5.8 size of targets (24 px) | entries and the fold button are 40 px high, the switch buttons 34 px | yes |
| 2.3.3 motion | the only transitions (the arrow of the fold button, the progress bar of the wizard) are switched off under `prefers-reduced-motion` | yes |
| placeholders | `--text-faint`, at least 5.1:1 on the field | yes |

What the audit found and this page's change fixed: no way past the menu for the keyboard; unnamed `aside`; the dashboard cards started at `h3` below the `h1`; the dashboard cards were wider than a 320 px screen; the default colour of placeholders (3.75:1); the border of the switch was decoration only; no reduced-motion rule.

**Not checked:** a screen reader (NVDA, VoiceOver); a high-contrast mode of the system (`forced-colors`); a user's own text spacing; zoom beyond what a 320 px screen shows; touch use on a device. The badges use `color-mix` for their tint; a browser without it shows the text and the border in the status colour and no tint, which keeps the contrast. Run the audit again after a change of a colour or of the markup of a page.

## Where it is used

`public/app.css` and `public/fonts/` (all pages of the interface), `src/Admin/View.php` and `Icons.php` (the menu, the switch), `src/Web/Html.php` (`css()`, the restore wizard; it has no menu and no switch and follows the system). The tests do not look at colours; contrast was computed from the values above with the formula of WCAG 2.x and the pages were looked at in both themes at desktop width and at 375 px.
