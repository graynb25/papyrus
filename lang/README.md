# Translation catalogs

English is the source language. Spanish (`es`), French (`fr`), and Brazilian Portuguese
(`pt_BR`) are translated catalogs. All three are AI drafts, have not been reviewed by
native speakers, and must not be described as reviewed.

## Layout

- `lang/{locale}.json` is the sentence catalog. Its keys are the English source sentences.
  English has no JSON file because its keys are already the copy.
- `lang/{locale}/validation.php`, `auth.php`, `passwords.php`, and `pagination.php` are the
  framework groups. They mirror the framework key shape.
- Every translated catalog must carry exactly the same keys as `lang/es.json`, and every
  framework group must have the same key shape as its Spanish counterpart.
- The Spanish catalog is the frozen terminology glossary for the French and Brazilian
  Portuguese drafts. Translate from the English source meaning, not word for word from
  Spanish.

## Status

- Spanish covers the authentication pages, the app shell, the library, the series page, the
  reader, search, browse, downloads, insights, sources, health, profile, appearance,
  security, connections, about, licences, backups, import, the nine first-run tour steps,
  and the 403, 404, 419, 429, 500, and 503 error pages.
- French and Brazilian Portuguese mirror the same key set and are selectable as of this
  change.
- All three catalogs are AI drafts. Native review is required before any of them is called
  reviewed.

## Still English

- Legal pages and the FAQ, including the FAQ inside Settings.
- The Windows installer and the launcher.
- Technical error text that comes from a source, the manga server, a passkey, or an
  exception. The label around it is translated; the raw message is not.
- Progress notes written by background backup and import jobs remain as they were stored.
  The page's status words, labels, and surrounding copy are translated.
- Extension store names and index URLs, manga titles, chapter names, scanlator names,
  package names, URLs, file paths, and source metadata. These are reader data rather than
  UI copy.

## Guidelines

- Keys are the English source sentences.
- Preserve `:placeholders` exactly.
- Preserve every plural branch and every `{1}` or `[2,*]` selector.
- Preserve HTML fragments and punctuation contracts.
- Use UTF-8 JSON without a byte order mark and LF line endings.
- The project does not allow em dashes or en dashes anywhere.
- Native review is required before a catalog is called reviewed.
