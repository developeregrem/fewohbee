---
name: translate-release-notes
description: Translate German release notes in docs/release-notes/ into English, producing <version>.en.md from <version>.de.md. Use when a German release note has no English counterpart, or when the German file changed after the English one was written.
---

# Translate release notes

German is the source of truth and is written by hand. English is derived from it.

## What to do

1. List `docs/release-notes/*.de.md`.
2. For each one, translate it when the matching `<version>.en.md` is **missing**, or when the
   German file is **newer** than the English one (`git log -1 --format=%ct -- <file>`).
3. Write the result to `docs/release-notes/<version>.en.md`.
4. Leave every other file alone. Never edit a `.de.md`.

If nothing needs translating, say so and stop — do not rewrite files that are already current.

## Preserve exactly

These notes are rendered inside the application and also become the GitHub release body, so
structure is not decoration:

- **Heading levels** (`##`, `###`) and their order.
- **Emoji**, including the leading emoji in headings. Keep them on the same items.
- **The ⚠️ and 📖 conventions.** `⚠️ **When upgrading:**` marks a manual step the operator must
  take; `📖` marks a documentation link. Both must survive translation with the same marker.
- **Links**, including the URL. Only translate the link text. Documentation URLs contain a locale
  segment — `.../de/documentation/...` becomes `.../en/documentation/...`, nothing else changes.
- **Code spans and blocks** verbatim, including placeholders like `RE-<year>-<number:4>`.
- **The YAML front matter** (`date:`) unchanged.

## Terminology

Use the wording the English interface already uses, not a literal translation. These come from
`translations/*/messages.en.yaml`:

| German | English |
|---|---|
| Zimmer | Room |
| Zimmerkategorie | Room category |
| Niederlassung | Branch |
| Reservierungsherkunft | Reservation origin |
| Preisliste | Prices |
| Beherbergungsabgabe / Kurtaxe | Tourist tax |
| Automatisierung | Automation |
| Buchung | Booking |
| Reservierung | Reservation |
| Rechnung | Invoice |
| Rechnungsnummernkreis | Invoice number range |
| Gast | Guest |
| Belegung / Auslastung | Occupancy |
| Aufschlag | Surcharge |
| Ermäßigung | Reduction |
| Online-Buchung | Online booking |
| Kalender-Import | Calendar import |
| Vorlage | Template |
| Betriebsstätte | Property |

When a term is not in the table, check `translations/` for how the interface names it before
inventing wording.

## Register

British English, plain and concrete. Match the German original's tone: it addresses the hotelier
directly and explains *what changes for them*, not what was implemented. Do not add marketing
language, and do not add or drop bullet points — one bullet in, one bullet out.

## Before finishing

Re-read your output against the German and check that the heading structure, the bullet count, every
link and every ⚠️/📖 marker match. A dropped upgrade warning is the worst possible failure here,
because operators rely on it.
