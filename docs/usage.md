---
title: Usage
slug: usage
order: 30
summary: Rendering feeds, reference tags, the Feed field, publishing channels, importing and the console.
---

## Putting a feed on a page

The one-liner:

```twig
{{ craft.airwave.render('craft-news') }}
{{ craft.airwave.render('craft-news', { limit: 3, layout: 'cards', showReadMore: true }) }}
```

`layout` is `list`, `cards` or `titles`. The other options are `limit`, `showImages`,
`showExcerpt`, `showDate`, `showAuthor`, `showSource` and `showReadMore`.

### Merged streams (Pro)

Several feeds as one stream, newest first:

```twig
{{ craft.airwave.renderMerged(['craft-news', 'php-weekly'], { limit: 10 }) }}
```

Items are ordered by `COALESCE(publishedAt, fetchedAt)`, so undated items — which are common — sort
by when Airwave saw them rather than falling to the bottom or the top depending on your database.

### In rich text

Every feed has a reference tag. Paste it into a CKEditor, Redactor or plain HTML field:

```
{airwave:craft-news:render}
```

Craft parses reference tags over every rich-text value before it reaches your template, so this
works with no template changes and no per-editor integration. It is the closest thing Craft has to
a shortcode, and it is what to reach for when the person choosing where the feed goes is an author
rather than a developer.

### With a field

Add a **Feed** field to an entry type and an author can choose which feeds appear on that page:

```twig
{{ entry.sidebarFeeds }}
{{ entry.sidebarFeeds.render({ limit: 3 }) }}
{% for item in entry.sidebarFeeds.items({ limit: 5 }) %}…{% endfor %}
```

## Writing your own markup

If you would rather not use the built-in renderer at all, query the items:

```twig
{% for item in craft.airwave.items({ source: 'craft-news', limit: 5 }) %}
    <a href="{{ item.getLink() }}">{{ item.titleText }}</a>
    <p>{{ item.getExcerpt(160) }}</p>
    {% if item.thumbnailUrl %}<img src="{{ item.thumbnailUrl }}" alt="">{% endif %}
{% endfor %}
```

`craft.airwave.items()` takes `source` (a handle or an array of them), `limit`, `offset`,
`search` and `since`.

### Item properties

| | |
|---|---|
| `item.titleText` | The title, as plain text |
| `item.getLink(options)` | The URL, with any link rewriting applied |
| `item.getExcerpt(200)` | Plain-text summary, trimmed on a word boundary |
| `item.getHtml('summary'\|'full')` | Purified HTML |
| `item.thumbnailUrl` | The item's image, wherever the publisher hid it |
| `item.publishedAt` | A `DateTime`, or **null** — feeds do omit dates |
| `item.author`, `item.categories`, `item.domain` | |
| `item.enclosureUrl`, `item.enclosureType`, `item.enclosureLength` | For podcasts |
| `item.sourceName`, `item.sourceHandle` | Which feed it came from |

There is no raw path to feed content. `getHtml()` purifies; the excerpt and the title are plain
text. A title is `strip_tags()`ed rather than escaped, because a feed title is specified as text.

### Styling

Airwave ships one small stylesheet that sets layout and nothing else — no colours, no fonts — so a
feed looks like the page it is on. Override the custom properties:

```css
.airwave { --airwave-gap: 2rem; --airwave-thumb: 140px; }
```

Or take the markup over completely by putting your own at `templates/_airwave/items.twig`. Airwave
finds it automatically and hands it `items`, `options`, `source` and `meta`. Start by copying
`src/templates/_render/items.twig` out of the plugin.

## Publishing a feed

A channel broadcasts either **your own content** or **the feeds Airwave has collected** — the
second is how you publish a curated "best of the web" feed without writing anything at all.

Because an entry being broadcast and an item that arrived from somebody else's RSS become the same
shape internally, a channel can re-broadcast aggregated items with no glue at all.

In your `<head>`:

```twig
{{ craft.airwave.autoDiscovery() }}
```

and anywhere else:

```twig
<a href="{{ craft.airwave.channelUrl('blog') }}">Subscribe</a>
```

Published feeds are cached and send `Cache-Control` accordingly, so well-behaved readers stop
asking. Aggregated items are purified on the way out as well as on the way in — a channel that
re-broadcasts somebody else's markup is republishing it under your domain.

## Importing items as entries (Pro)

On a feed's **Import** tab, choose a section and entry type, set the field mapping (see
[Configuration](configuration#import-mapping-pro)), and press **Show what would be imported**
first. It writes nothing.

### Nothing is ever imported twice

Dedupe is by the row that records *this item became that entry* — not by title, not by URL. It
survives the entry being renamed, moved, re-slugged or edited beyond recognition. Deleting the
entry does not invite the article back on the next refresh.

For the same reason, **pruning never touches an imported item**. Its entry points at it, and
deleting the row would let the item re-import as a duplicate.

### Large first imports

Runs are capped — 25 items by default — and continue in the background. A first import of a feed
with 800 items in it is a chain of small jobs rather than one request that never ends.

## The command line

```sh
php craft airwave/feeds                      # every feed, with its health
php craft airwave/feeds/refresh              # fetch them all
php craft airwave/feeds/refresh --due        # only the ones whose interval has elapsed
php craft airwave/feeds/refresh --handle=craft-news --force
php craft airwave/feeds/import --dry-run     # what importing would do
php craft airwave/feeds/prune --keep=50
php craft airwave/feeds/discover example.com # find the feeds a site advertises
php craft airwave/feeds/test <url>           # fetch and parse without subscribing

php craft airwave/feeds/reapply-filters --handle=craft-news   # Pro; re-run filters over stored items
php craft airwave/feeds/disable --handle=one,two             # also enable, delete; or --all
php craft airwave/items/delete --handle=craft-news --before=2024-01-01 --dry-run
php craft airwave/items/delete --ids=12,13                   # imported items need --include-imported

php craft airwave/channels                   # every published feed and its URL
php craft airwave/channels/preview blog      # print the document
php craft airwave/channels/disable --handle=blog             # also enable, delete; or --all

php craft airwave/alerts/check               # evaluate every feed, send any alert or recovery owed
php craft airwave/alerts/test                # send a sample alert to the saved recipients/webhook
```

Every bulk action in the control panel has a console equivalent that goes through the same service:
**Refresh** and **Refresh all**, **Re-apply filters**, **Import**, deleting items, and the feed and
channel indexes' **Set status** and **Delete**. Bulk commands want `--handle` (comma-separated) or
`--all`, ask before deleting anything, and take `--interactive=0` in scripts.

`airwave/feeds/test` is the one to reach for when a feed is not behaving. It fetches, parses and
prints what Airwave made of the document, without subscribing to it and without touching the site.
See [Troubleshooting](troubleshooting).
