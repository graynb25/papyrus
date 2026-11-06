# Translation catalogs

The Spanish catalog is an AI draft for the Phase 1 and Phase 2 slices. It has not been reviewed by a native speaker, and it must not be described as reviewed.

Phase 1 covers the authentication pages, the app shell, the library, the series page, the reader, and the 404 and 500 error pages. Phase 2 covers search, browse, downloads, insights, sources, health, the source-language names shown in search and source cards, and the static health summary plurals. The Spanish framework groups in `lang/es/` cover validation, authentication, passwords, and pagination. The catalog also carries the Fortify password-rule sentences and the verification and reset mail sentences.

French and Brazilian Portuguese remain configured target codes but are not selectable or resolvable until their catalogs exist.

Still English:

- Legal pages and the FAQ.
- Settings bodies, backups, import, connections, about, and licences.
- The Windows installer, the launcher, and the nine first-run tour step bodies.
- 403, 429, and 503 error pages.
- Technical error text that comes from a source or the manga server. The label around it is translated; the raw message is not.
- Extension store names and index URLs, manga titles, chapter names, scanlator names, and source metadata. These are reader data rather than UI copy.

Guidelines:

- Keys are the English source sentences.
- Preserve `:placeholders` exactly.
- Preserve plural branch counts.
- Use UTF-8 JSON without a byte order mark.
- The project does not allow em dashes or en dashes anywhere.
