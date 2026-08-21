---
title: Troubleshooting
slug: troubleshooting
order: 40
summary: Feeds that will not parse, items that do not appear, channels that 404, and what to check first.
---

## Start here

```sh
php craft airwave/feeds/test https://example.com/feed.xml
```

This fetches the document, parses it, and prints what Airwave made of it — the dialect it detected,
the feed title, and the first few items with their dates, links and images. It subscribes to
nothing and writes nothing, so it is safe to run against anything.

If `test` is happy and the site is not, the problem is downstream: filters, the prune ceiling, or a
template.

## The feed will not parse

**"No feed found at that URL."** The document is not a feed. Try `airwave/feeds/discover` on the
site address, or the **Find the feed** button on the feed screen — many sites advertise a feed at a
URL you would never guess.

Airwave sniffs the document rather than trusting the `Content-Type`, so a feed served as
`text/html` is fine. What is not fine:

- **A `DOCTYPE` declaration.** Airwave refuses these outright rather than parsing with entity
  loading disabled, because "disabled" is a property of a `php.ini` somebody else controls, and a
  billion-laughs payload costs nothing to send. A feed with a DOCTYPE has to be fixed by its
  publisher.
- **A truncated document.** Check whether the feed is larger than the **Maximum response size**
  setting.
- **A redirect to a private address.** If the feed redirects to something on a private or reserved
  range, Airwave stops there. See **Allow private network fetches** in
  [Configuration](configuration#allow-private-network-fetches).

A UTF-8 BOM before `<?xml` makes libxml refuse a document — Airwave strips it, so if you have seen
that break another tool, it will not break here.

## Items are missing

In order of likelihood:

1. **A filter rejected them.** Filters run on the way in, so a rejected item is not merely hidden —
   it was never stored. Loosen the rule and wait for the next refresh, or use **Reapply filters**.
2. **The prune ceiling.** **Items to keep** is doing exactly what it says.
3. **Maximum age.** Note that undated items are always kept: dropping them would silently empty a
   feed whose publisher does not date anything.
4. **The feed genuinely changed.** Compare with `airwave/feeds/test`.

## Everything is dated today

It is not. Airwave stores a missing date as **null** rather than defaulting it to now, precisely so
that a backfilled feed does not look like breaking news. If items are rendering with today's date,
your own template is probably falling back to `now` — `item.publishedAt` can be null, and should be
checked:

```twig
{% if item.publishedAt %}{{ item.publishedAt|date('j M Y') }}{% endif %}
```

## The feed is empty on the page but fine in the CP

- Check the handle in `craft.airwave.render()` matches the feed's handle.
- Check the **site** — feeds are not localized, but a template that filters items by something
  site-specific may be.
- Clear the compiled template cache if you have just overridden `templates/_airwave/items.twig`:

  ```sh
  php craft clear-caches/compiled-templates
  ```

## The feed went stale and the page is still showing old items

That is deliberate. **A failed refresh never empties a feed** — stored items keep rendering and
only the feed's health indicator changes. Open the feed in the CP to see the last error.

If refreshes are not happening at all, check whether **Refresh on the front end** is off without a
cron job to replace it:

```sh
php craft airwave/feeds/refresh --due
```

## A channel URL returns a 404

- Is the channel **enabled**?
- Does the URI collide with a section's URI format, a route, or a real file in `web/`?
- Did the channel save while a licence was lapsed? The CP refuses to *save* an Atom channel on
  Lite, but never 404s one that was already saved — so a 404 is a routing problem, not a licensing
  one.
- Clear caches. The channel route map is read on every front-end request and is cached; every write
  path invalidates it, but a manual database edit will not have:

  ```sh
  php craft clear-caches/all
  ```

## A channel serves the wrong content

A channel has a **target site** — the site whose content it broadcasts — which is a different thing
from the site it happens to be served from. On a single-site install these are the same and the
distinction never shows up. On a second site it does. Check the channel's site setting.

## Imports are not creating entries

- Import is **Pro**. On Lite the tab explains rather than imports.
- Press **Show what would be imported** — it reports what it would skip and why.
- An item that was already imported is skipped forever, by design, even if you deleted the entry.
- If the entry type has **no title field and no title format**, Craft fails with "Slug cannot be
  blank." Airwave supplies a slug itself for title-less types, so if you see that error, check
  whether something else in your setup is saving the entry.

## Images are missing

Airwave looks for an item's image in the enclosure, `media:content`, `media:thumbnail`, the JSON
Feed image key, and the first real image in the content. It skips tracking pixels — a 1×1 image is
not a thumbnail — but it measures them numerically, so a legitimate `width="600"` is never mistaken
for a two-digit tracking pixel.

If the image is present in the feed and still missing, run `airwave/feeds/test` and check what
Airwave found; publishers hide images in remarkable places.

## Purifier errors on a fresh install

HTML Purifier will not create its own cache directory. Airwave creates it before purifying, so this
should not happen — but if you see a permissions error on `storage/runtime/`, that is the cause.

## Still stuck

Run the two things that describe the whole state:

```sh
php craft airwave/feeds        # every feed, with its health and last error
php craft airwave/channels     # every published feed and its URL
```

and open an issue at
[github.com/justinholtweb/craft-airwave/issues](https://github.com/justinholtweb/craft-airwave/issues)
with the output.
