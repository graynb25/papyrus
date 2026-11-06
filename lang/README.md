# Translation catalogs

The Spanish catalog is an AI draft for the Phase 1 slice. It has not been reviewed by a native speaker, and it must not be described as reviewed.

Phase 1 covers the authentication pages, the app shell, the library, the series page, the reader, and the 404 and 500 error pages. The Spanish framework groups in `lang/es/` cover validation, authentication, passwords, and pagination. The catalog also carries the Fortify password-rule sentences and the verification and reset mail sentences.

French and Brazilian Portuguese remain configured target codes but are not selectable or resolvable until their catalogs exist.

Still English in this phase:

- Legal pages and the FAQ.
- Backups, import, connections, insights, sources, health, search, browse, and downloads.
- The Windows installer, the launcher, and the nine first-run tour step bodies.
- Technical error text that comes from a source or the manga server.

Guidelines:

- Keys are the English source sentences.
- Preserve `:placeholders` exactly.
- Preserve plural branch counts.
- Use UTF-8 JSON without a byte order mark.
- The project does not allow em dashes or en dashes anywhere.
