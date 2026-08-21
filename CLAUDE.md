# Airwave — Craft CMS 5 Plugin

## Project Overview

Airwave reads RSS/Atom/JSON feeds into a Craft site and publishes feeds back out of it — Feedzy's
job on the inbound side, plus an outbound broadcaster the WordPress plugin does not have.
Distributed as `justinholtweb/craft-airwave`. Lite (free) + Pro editions.

## Tech Stack

- **PHP 8.2+**, **Craft CMS 5.3+**, Yii2, Twig
- No build step and no JavaScript runtime on the front end: a list of feed items is a list. The CP
  edit screens carry a little inline `{% js %}` for the buttons that post with
  `Craft.sendActionRequest`.
- No feed-parsing dependency. `simplexml` + `json_decode` directly, HTML Purifier via Craft's own.

## Architecture

### Namespace & package

- Namespace: `justinholtweb\airwave`
- Package: `justinholtweb/craft-airwave`
- Handle: `airwave`

### The load-bearing idea: one item shape

`models\Item` is what a parsed RSS `<item>`, an Atom `<entry>`, a JSON Feed object, a row in
`airwave_items` and *an entry being broadcast outward* all become. Because there is one shape,
there is one filter engine, one store, one renderer and one serialiser instead of four of each —
and a channel can re-broadcast aggregated items without a single line of glue.

Nothing downstream of `services\Parser` ever asks what dialect something arrived in.

### Data model

- `elements\Source` — a feed you subscribe to. Not localized: a feed is the same feed on every
  site. `refHandle() = 'airwave'`, so `{airwave:craft-news:render}` renders it inside any rich-text
  field, exactly the way Legs does it — Craft's `parseRefs()` splices `getRender()` in raw.
- `elements\Channel` — a feed you publish. Also not localized, but carries **`targetSiteId`**, which
  is a different thing from Craft's own `siteId` and must not be conflated (the bug only shows up
  on the second site). `hasUris()` is false: Airwave routes channels itself rather than putting
  them in `elements_sites.uri`, because a channel is not a page and Craft's URI machinery would
  make it one.
- `{{%airwave_items}}` — **deliberately not elements.** A feed republished hourly for a year is
  ~9,000 rows nobody edits, searches, relates, revises or restores. Paying `elements` +
  `elements_sites` + the search index for each would buy nothing. Items that *become* entries get
  real elements, pointed at from `importedElementId`.
- Dedupe is `(sourceId, guidHash)` where `guidHash = sha1(guid ?: url ?: title)`. A GUID is
  routinely a 2KB permalink, and feeds exist whose GUIDs differ only past the 191st character.

### Services

- `fetcher` — conditional GET, SSRF guard, size ceiling
- `parser` — four dialects → `ParsedFeed`; also feed discovery and purification
- `items` — the store: upsert, dedupe, prune, query
- `sources` — the library and `refresh()`, which reads top to bottom as the whole inbound story
- `filters` — rules over incoming items, plus the explicit `reapply()` (Pro)
- `importer` — items → entries (Pro)
- `channels` — routing and contents
- `broadcaster` — items → RSS/Atom/JSON
- `renderer` — items → a page

### Rules the code does not break

- **A failed refresh never empties a feed.** Stored items stay and keep rendering; only the health
  indicator changes. A publisher's bad afternoon should not blank a section of somebody's homepage.
- **Filters run on the way in.** An item that was never wanted is never stored, and therefore can
  never be imported into an entry by accident. Changing the rules only affects future items, which
  is why `Filters::reapply()` exists and is explicit — it deletes things.
- **Pruning never touches an imported item.** Its entry points at it, and a deleted row re-imports
  as a duplicate.
- **There is no raw path to feed content.** `Item::getHtml()` purifies; the broadcaster purifies
  aggregated content again on the way out. Titles are `strip_tags()`ed rather than escaped, because
  a feed title is specified as text.
- **Editions downgrade on the way out, refuse on the way in.** The CP refuses to *save* an Atom
  channel on Lite; a channel already saved as Atom when a licence lapses *serves* RSS rather than
  404ing, because that URL has been in subscription lists for a year.

## Traps found while building this

- **`Db::upsert()`'s insert half is parsed whether or not it is used.** Leaving `fetchedAt` out of
  the columns for a row that already exists still fails under MySQL strict mode with "Field
  'fetchedAt' doesn't have a default value". Always supply the NOT NULL columns in the insert set;
  put only what should change in the update set. Signature is
  `upsert($table, $insert, $update, $params, $updateTimestamp, $db)` — six arguments, and passing
  the connection fourth is a `TypeError`.
- **`craft\web\Response` has no `setRawContent()` and no `setDownloadHeaders()`.** Use the
  controller's `asRaw()`, which sets `FORMAT_RAW` as well. Setting the body any other way lets
  Craft try to render the string.
- **Twig macro names are case-sensitive.** `forms.copyTextBtn` is a Twig *runtime* error, not a
  missing button. The macro is `forms.copytext`, all lowercase.
- **`width="600"` matches a "one or two digits" regex.** The tracking-pixel guard originally
  pattern-matched `[0-9]{1,2}` and threw away every real image in every feed. Capture the whole
  number and compare it numerically.
- **HTML Purifier will not create its own cache directory** and throws instead, which turns the
  first purified item on a fresh install into a 500. `FileHelper::createDirectory()` first.
- **An entry type with no title field and no title format fails with "Slug cannot be blank."**
  Title-less entry types are ordinary in Craft 5, and the error reads like an Airwave bug. The
  importer supplies a slug itself when the type has nothing to build one from.
- **Sniff the document, never the `Content-Type`.** Feeds are served as `text/html`, `text/plain`
  and `application/octet-stream` constantly.
- **A UTF-8 BOM before `<?xml` makes libxml refuse the whole document.** Enough feeds are served
  with one that this is not theoretical.
- **Refuse a `DOCTYPE` outright** rather than parsing with entity loading disabled — "disabled" is
  a property of a php.ini somebody else controls, and billion-laughs costs nothing to send.
- **Checking only the URL an author typed is the same as not checking.** An open redirect on a
  public host walks straight past it, so `on_redirect` re-validates every hop.
- **Missing dates must stay null.** Defaulting to `now()` makes every backfilled item look like
  breaking news, and `maxAgeDays` must *keep* undated items or a feed silently empties.
- **Order by `COALESCE(publishedAt, fetchedAt)`.** Undated items are common, and NULL ordering
  differs between MySQL and Postgres.
- **The channel route map is read on every front-end request**, so it is cached and every write
  path invalidates it explicitly. A saved channel missing from the map is a feed URL that 404s.
- **A soft-deleted feed keeps its row and its handle**, so both handle and URI are parked under a
  `--trashed-<id>` suffix on delete and reclaimed on restore. Same trade as Legs.
- **Project config writes from a console script are buffered.** A `switchEdition()` at the end of a
  test script is simply lost without `saveModifiedConfigData()`.

See also `[[craft-plugin-gotchas]]` in the shared memory for family-wide traps.

## Testing

No local PHP on this Mac. Everything runs inside the plugin-testing container:

```sh
cd ~/Sites/plugin-testing
ddev exec php /var/www/craft-airwave/tests/integration/checks.php     # 150 checks
ddev exec bash /var/www/craft-airwave/tests/integration/cp-smoke.sh   # every CP screen + write paths
ddev exec bash -c 'find /var/www/craft-airwave/src -name "*.php" -print0 | xargs -0 -n1 php -l'
```

`checks.php` cleans up from a **shutdown handler**, not off the end of the script: a fatal above
used to leave feeds behind, which then counted against the Lite limit and made the next run fail
for a reason that had nothing to do with the code. It also restores whichever edition it found.

`tests/integration/demo.php` sets up a live feed (`craft-news`) and a channel at
`/airwave-demo.xml` in the harness, and `templates/airwave-render-test.twig` there renders it three
ways. The CP smoke test needs a login — `admin` / `claudepassword` locally, or `AIRWAVE_CP_USER`
and `AIRWAVE_CP_PASS`.

The checks that matter most are the **round trips**: build a document with the broadcaster, read it
back with the parser, compare. They catch escaping bugs — ampersands, CDATA containing `]]>`,
control characters — that no assertion about a string would.

## Coding conventions

- `Craft::t('airwave', '…')` for user-facing strings; `src/translations/en/airwave.php` lists them
- Business logic in services; controllers stay thin
- Never nest a `<form>` in a CP template — post secondary actions with `Craft.sendActionRequest`
- Never mark plugin settings `required`
