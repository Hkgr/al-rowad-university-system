# Self-hosted Cairo

Original, unmodified WOFF2 variable subsets distributed by Google Fonts (Cairo **v31**, normal, **wght 200–1000**). Downloaded 2026-09-09. No font build/subsetting/conversion was performed locally.

Designers: Mohamed Gaber, Accademia di Belle Arti di Urbino. Upstream: https://github.com/Gue3bara/Cairo . License: SIL Open Font License 1.1, included in `frontend/public/licenses/cairo/OFL.txt` so the copyright and full license also ship in the production build.

Google Fonts metadata/license reference: https://github.com/google/fonts/tree/a2c83daed9bc723922de8d0a9512990bd3d91df4/ofl/cairo . Metadata names upstream commit `73d16933c6a0f341c27a69e401da83dcb0d53114` and weight axis 200–1000. WOFF2 distribution URLs below are the authoritative bytes bundled here, not a claim of a locally reproduced build.

Download-time stylesheet: https://fonts.googleapis.com/css2?family=Cairo:wght@200..1000&display=swap&subset=arabic (modern Chrome user agent). Its three subset unicode ranges are preserved in `src/styles/cairo.css`.

| Local file | Original URL |
| --- | --- |
| cairo-arabic-wght.woff2 | https://fonts.gstatic.com/s/cairo/v31/SLXVc1nY6HkvangtZmpQdkhzfH5lkSscQyyS4J0.woff2 |
| cairo-latin-ext-wght.woff2 | https://fonts.gstatic.com/s/cairo/v31/SLXVc1nY6HkvangtZmpQdkhzfH5lkSscSCyS4J0.woff2 |
| cairo-latin-wght.woff2 | https://fonts.gstatic.com/s/cairo/v31/SLXVc1nY6HkvangtZmpQdkhzfH5lkSscRiyS.woff2 |

Arabic, Latin, Latin extensions, ASCII digits and Arabic/Eastern Arabic digits are covered by these subsets. The application uses normal through black weights (including 500/600/700/800/900); the variable range covers them without separate static files. These are UI assets, not new PDF-generator code.

All runtime sources are relative Vite imports. There is **no** Google Fonts/CDN request and **no** `local()` lookup at runtime. Vite emits hashed assets, respecting its configured base URL. Do not replace these with links to the download URLs.
