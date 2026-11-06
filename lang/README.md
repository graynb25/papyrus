# Translation catalogs

English is the source language. Spanish (`es`), French (`fr`), and Brazilian Portuguese
(`pt_BR`) are translated catalogs. All three are AI drafts. An independent AI language
review has been completed, but no native speaker has reviewed them, and they must not be
described as native-reviewed.

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
  security, connections, about, licences, backups, import, the series and source migration
  wizard, the backup, restore, export, local-folder and adult-content status messages, the
  nine first-run tour steps, and the 403, 404, 419, 429, 500, and 503 error pages.
- French and Brazilian Portuguese mirror the same key set and are selectable as of this
  change.
- The catalog test in `tests/Feature/Localization/SpanishCatalogTest.php` reads literal
  `__()` and `trans_choice()` calls in the covered reader-facing files, including
  concatenated strings, so a sentence added there without a catalog entry fails the test.
- All three catalogs are AI drafts. An independent AI language review has been completed;
  native-speaker review has not been performed.

## Still English

- Legal pages and the FAQ, including the FAQ inside Settings.
- The Windows installer and the launcher.
- Console command output, which is operator-facing rather than reader-facing.
- Technical error text that comes from a source, the manga server, a passkey, or an
  exception. The label around it is translated; the raw message is not.
- Progress notes written by background backup and import jobs remain as they were stored.
  The page's status words, labels, and surrounding copy are translated.
- The pre-rendered Laravel maintenance page can stay in the language that was active when
  the install was taken down. A thrown 503 response uses the normal locale path.
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
- Native review is still recommended before a catalog is described as native-reviewed.
