# Design files

| File | What |
|---|---|
| `logo.svg` | The logo: a graphite tile with a safe door in brass outline and, stepping out of it, the brass disc with the restore arrow. 4500 x 4500 units like the logos of the other applications of the author. |
| `logo-icon.svg` | The mark as an icon, 24 x 24, drawn with `currentColor` so that it takes the colour of the text around it. Safe with hinges and dial, restore arrow with clock hands. Good from 24 px up. |
| `logo-icon-simple.svg` | The same for 16 px: the arrow ring becomes a plain clock. |
| `logo.png`, `logo.jpg` | The logo as pictures, 2000 x 2000 (the PNG has a transparent frame around the tile), for places that take no SVG. |
| `github-social-preview.png` | The image for *Settings → General → Social preview* of the GitHub repository, 1280 x 640: the logo and the wordmark in IBM Plex Sans on the dark ink, the colours of the tokens. Uploaded by hand on GitHub. |
| `ts-cover.jpg` | The cover for the page of the application on the-loom.de, 1600 x 700 like `sl-cover.jpg` of SetLister: the logo tile, the name in two lines in brass (Noto Sans Bold) and the line "Never lose a thread" on the slate blue `#3a4b5f`. |
| `favicon.svg` | The favicon: the logo with fewer and heavier parts. `public/favicon.svg` is the same as a file (`Web\Logo::favicon()` in the code; `LogoTest` keeps them equal). |

Colours are the tokens of [docs/DESIGN.md](../docs/DESIGN.md): graphite `#1b232c` (`--panel` in the dark scheme), brass `#e0a13d` (`--brass-fill`). In the program the logo is
markup in `src/Web/Logo.php` (see [docs/DESIGN.md](../docs/DESIGN.md) "The logo"); a change to a file here is made there too. `public/favicon.svg` is part of the
release package, the other files of this directory are not.
