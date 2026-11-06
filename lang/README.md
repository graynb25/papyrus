# Translation catalogs

The Spanish catalog is an AI draft for the Phase 1, Phase 2, and Phase 3 slices. It has not been reviewed by a native speaker, and it must not be described as reviewed.

Phase 1 covers the authentication pages, the app shell, the library, the series page, the reader, and the 404 and 500 error pages. Phase 2 covers search, browse, downloads, insights, sources, health, the source-language names shown in search and source cards, and the static health summary plurals. Phase 3 covers profile, appearance, security, connections, about, licences, backups, import, the nine first-run tour step bodies, and the 403, 429, and 503 error pages. The Spanish framework groups in `lang/es/` cover validation, authentication, passwords, and pagination. The catalog also carries the Fortify password-rule sentences and the verification and reset mail sentences.

French and Brazilian Portuguese remain configured target codes but are not selectable or resolvable until their catalogs exist.

Still English:

- Legal pages and the FAQ, including the FAQ inside Settings.
- The Windows installer and the launcher.
- Technical error text that comes from a source, the manga server, a passkey, or an exception. The label around it is translated; the raw message is not.
- Progress notes written by background backup and import jobs remain as they were stored. The page's status words, labels, and surrounding copy are translated.
- Extension store names and index URLs, manga titles, chapter names, scanlator names, package names, URLs, file paths, and source metadata. These are reader data rather than UI copy.

Guidelines:

- Keys are the English source sentences.
- Preserve `:placeholders` exactly.
- Preserve plural branch counts.
- Use UTF-8 JSON without a byte order mark.
- The project does not allow em dashes or en dashes anywhere.
