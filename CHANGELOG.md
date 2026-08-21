# Release Notes for Airwave

## 5.0.0 — 2026-08-18

Initial release.

Numbered 5.0.0 to match the Craft version it targets, in line with the rest of the family.

### Reading feeds

- Feeds are elements: index, search, permissions, trash and restore
- RSS 2.0, RSS 1.0 (RDF), Atom 1.0 and JSON Feed 1.1, sniffed from the document rather than the
  `Content-Type`
- Feed discovery — paste a site address and Airwave finds what it advertises
- Conditional GET with stored ETag and `Last-Modified`, so an unchanged feed costs a header exchange
- Refresh on a per-feed interval, inline on first read and queued when stale
- Items stored, deduplicated by GUID, updated in place when a publisher edits one, and pruned to a
  keep limit that never touches an imported item
- Keyword, category, author, date, image and length filters, with a re-apply pass for items already
  stored (Pro)

### Showing them

- `{{ craft.airwave.render('handle') }}`, merged streams, and the raw items
- `{airwave:handle:render}` reference tags, so a feed renders inside any rich-text field
- A Feed field, so an author picks which feeds appear on a page
- List, grid and card layouts, and a stylesheet that sets layout and nothing else
- Site-level template override at `templates/_airwave/items.twig`
- Link rewriting for UTM and affiliate parameters (Pro)

### Publishing feeds

- Channels are elements with a URI of your choosing, routed from a cached map
- Broadcast an element query, or the items Airwave has aggregated
- RSS 2.0 (Lite), Atom 1.0 and JSON Feed 1.1 (Pro)
- Sensible mapping out of the box, object templates when you want them (Pro)
- Response caching, `Cache-Control`, and auto-discovery tags for your `<head>`

### Importing

- Feed items become entries, with field mapping by token or Twig template (Pro)
- Images downloaded into a volume and set on an Assets field
- Tags created, categories matched
- Dedupe by the import record, so nothing arrives twice and deleting an entry does not bring it back
- Capped, resumable background runs, and a dry run that writes nothing

### Safety

- SSRF guard on every fetch, re-checked on every redirect hop
- Response size ceiling, streamed rather than buffered whole
- HTML Purifier over every item, on the way in and on the way out
- `DOCTYPE` documents refused outright rather than parsed
- Private feeds authenticated with a header whose value comes from `.env`
