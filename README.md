# Airwave

**Read the web's feeds. Publish your own.**

Airwave is an RSS, Atom and JSON Feed plugin for Craft CMS 5. It does both directions, and the
halves share one data model — so a feed you pull in can be re-broadcast on a feed you publish,
without writing a template.

- **In.** Subscribe to feeds, filter them, cache their items, render them on a page, and — on Pro
  — turn them into entries.
- **Out.** Publish RSS, Atom or JSON Feed from any element query, or from the items Airwave has
  collected.

If you have moved a site off WordPress and miss Feedzy's shortcode, `{airwave:handle:render}`
pasted into any rich-text field renders a feed exactly where you put it.

---

## Requirements

Craft CMS 5.3+ and PHP 8.2+.

## Installation

```sh
composer require justinholtweb/craft-airwave
php craft plugin/install airwave
```

## Editions

| | Lite (free) | Pro |
|---|---|---|
| **Price** | **Free** | **$59**, $29/year renewal |
| Feeds | 3 | Unlimited |
| Published channels | 1 | Unlimited |
| Output formats | RSS 2.0 | RSS, Atom, JSON Feed |
| Display, caching, refresh | ✓ | ✓ |
| Failing-feed alerts (email, Slack/Teams/webhook) and the Feed health widget | ✓ | ✓ |
| Reference tags and the Feed field | ✓ | ✓ |
| Keyword, category, author and date filters | | ✓ |
| Import items as entries | | ✓ |
| Full article content | | ✓ |
| Merged multi-feed streams | | ✓ |
| Per-feed refresh intervals | | ✓ |
| Link rewriting (UTM, affiliate) | | ✓ |
| Object templates for published items | | ✓ |

A licence that lapses does not break anything. Airwave **downgrades on the way out**: full content
serves as summaries, an Atom channel serves RSS, a merged stream serves its first feed. Stored
configuration is untouched, so renewing restores exactly what was there.

---

## Adding a feed

**Airwave → Feeds → New feed.** Paste a feed URL, or paste the site address and press
**Find the feed** — Airwave reads the page and offers whatever it advertises.

RSS 2.0, RSS 1.0 (RDF), Atom 1.0 and JSON Feed all work, and Airwave sniffs the document rather
than trusting the file extension or the `Content-Type`, because feeds are served as `text/html`
more often than anyone would like.

### Refreshing

Feeds refresh on their own interval (hourly by default). A feed that has **never** been fetched is
read inline the first time something asks for it, so a template does not render an empty box; a
feed that is merely stale queues a job, so a slow publisher can never become a slow page.

To drive it from cron instead, turn off **Refresh on the front end** in the settings and run:

```sh
php craft airwave/feeds/refresh --due
```

`--due` fetches only the feeds whose own interval has elapsed, so the schedule stays per feed
rather than becoming whatever the crontab says.

### Failing-feed alerts

A failed refresh never empties a feed, which also means nobody notices one. Under **Settings →
Airwave → Alerts**, give Airwave some email addresses (and, if you like, a Slack, Teams or JSON
webhook) and it sends **one** message when a feed has failed a few refreshes in a row (3 by
default) and one when it is refreshing again — never one per failure. Optionally it also tells you
when a feed has not refreshed at all for a while, which is how a missing cron line shows up.

Each feed has its own latch, a reopening straight after a recovery waits out a quiet period, and a
send that fails is retried rather than lost. The webhook only goes to public addresses, pinned and
without following redirects, and can be HMAC-signed. The **Feed health** Dashboard widget shows the
library's totals and every feed in trouble. See [Configuration](docs/configuration.md#failing-feed-alerts).

---

## Putting a feed on a page

The one-liner:

```twig
{{ craft.airwave.render('craft-news') }}
{{ craft.airwave.render('craft-news', { limit: 3, layout: 'cards', showReadMore: true }) }}
```

Several feeds as one stream, newest first (Pro):

```twig
{{ craft.airwave.renderMerged(['craft-news', 'php-weekly'], { limit: 10 }) }}
```

The items themselves, if you would rather write your own markup:

```twig
{% for item in craft.airwave.items({ source: 'craft-news', limit: 5 }) %}
    <a href="{{ item.getLink() }}">{{ item.titleText }}</a>
    <p>{{ item.getExcerpt(160) }}</p>
    {% if item.thumbnailUrl %}<img src="{{ item.thumbnailUrl }}" alt="">{% endif %}
{% endfor %}
```

### In rich text

Every feed has a reference tag. Paste it into a CKEditor, Redactor or plain HTML field:

```
{airwave:craft-news:render}
```

Craft parses reference tags over every rich-text value before it reaches your template, so this
works with no template changes and no per-editor integration.

### With a field

Add a **Feed** field to an entry type and an author can choose which feeds appear on that page.
Output it directly:

```twig
{{ entry.sidebarFeeds }}
{{ entry.sidebarFeeds.render({ limit: 3 }) }}
{% for item in entry.sidebarFeeds.items({ limit: 5 }) %}…{% endfor %}
```

### Styling

Airwave ships one small stylesheet that sets layout and nothing else — no colours, no fonts, so a
feed looks like the page it is on. Override the custom properties:

```css
.airwave { --airwave-gap: 2rem; --airwave-thumb: 140px; }
```

Or take the markup over completely by putting your own at `templates/_airwave/items.twig`. Airwave
finds it automatically and hands it `items`, `options`, `source` and `meta`. Start by copying
`src/templates/_render/items.twig` out of the plugin.

### Item properties

| | |
|---|---|
| `item.titleText` | The title, as plain text |
| `item.getLink(options)` | The URL, with any link rewriting applied |
| `item.getExcerpt(200)` | Plain-text summary, trimmed on a word boundary |
| `item.getHtml('summary'\|'full')` | Purified HTML |
| `item.thumbnailUrl` | The item's image, wherever the publisher hid it |
| `item.publishedAt` | A `DateTime`, or null — feeds do omit dates |
| `item.author`, `item.categories`, `item.domain` | |
| `item.enclosureUrl`, `item.enclosureType`, `item.enclosureLength` | For podcasts |
| `item.sourceName`, `item.sourceHandle` | Which feed it came from |

---

## Publishing a feed

**Airwave → Channels → New channel.** A channel is a URI on your site plus enough configuration to
answer it.

Broadcast **your own content** — pick a section, and Airwave works the rest out: the entry title,
its URL, a summary or excerpt field, the author, and the first image field it finds. On Pro you can
replace any of those with an object template:

```twig
{{ object.body }}
{{ object.author.fullName }}
{{ object.featureImage.one().url ?? '' }}
```

Or broadcast **the feeds Airwave has collected**, which is how you publish a curated "best of the
web" feed without writing anything at all.

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

---

## Importing items as entries (Pro)

On a feed's **Import** tab, choose a section and entry type, decide whether new entries arrive
enabled or disabled, and map the parts of an item onto your fields.

Map with a token — `summary`, `content`, `url`, `author`, `image`, `categories`, `guid`, `domain`,
`publishedAt` — or with a Twig template for anything else:

```twig
{{ item.summary }} <p><a href="{{ item.url }}">Read the original on {{ item.domain }}</a></p>
```

Categories map onto a Tags field by creating tags, and onto a Categories field by matching existing
ones — an aggregator inventing branches in your category tree is not a favour.

**Nothing is ever imported twice.** Dedupe is by the row that records *this item became that
entry*, not by title or URL, so it survives the entry being renamed, moved or edited. Deleting the
entry does not invite the article back on the next refresh.

Runs are capped (25 items by default) and continue in the background, so a first import of a feed
with 800 items in it is a chain of small jobs rather than one request that never ends. Press
**Show what would be imported** first — it writes nothing.

---

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

`airwave/feeds/test` is the one to reach for when a feed is not behaving: it fetches, parses and
prints what Airwave made of it, without touching the site.

---

## Settings

Most settings are defaults a single feed can override. Two are deliberately site-wide, because
they are safety rails rather than preferences:

- **Allow private network fetches** — off. A feed URL is user input in any site where a non-admin
  can add one, and `http://169.254.169.254/…` is the standard way that ends badly. Airwave resolves
  every host and refuses private and reserved addresses, and re-checks every hop of a redirect
  chain. Turn this on only for a feed on your own network.
- **Clean incoming HTML** — on. Every item goes through HTML Purifier before it can reach a page.
  Airwave's configuration allows images, links, headings, lists and video embeds, and blocks forms,
  style blocks, scripts and `id` attributes that would collide with your page. Point it at your own
  `config/htmlpurifier/*.json` if you want something else.

There is deliberately no password field for a private feed. Add a request header on the feed
instead — header values are run through `App::parseEnv()`:

```
Authorization: $FEED_TOKEN
```

so the token stays in `.env` rather than in the database and in every backup of it.

---

## Permissions

| | |
|---|---|
| View feeds and items | Read the CP screens |
| Add and edit feeds | Subscribe and configure |
| Delete feeds and items | |
| Refresh feeds | Fetch on demand |
| Import items as entries | Separate on purpose — importing writes into a live section, which is a larger thing than subscribing to a URL |
| Publish and edit channels | |

---

## Testing

Inside the plugin-testing harness:

```sh
ddev exec php /var/www/craft-airwave/tests/integration/checks.php   # 167 checks
ddev exec php /var/www/craft-airwave/tests/integration/alerts.php   # 44 checks: alerts, widget, test endpoint over HTTP
ddev exec php /var/www/craft-airwave/tests/integration/console.php  # 15 checks: every console bulk action, run for real
ddev exec bash /var/www/craft-airwave/tests/integration/cp-smoke.sh # every CP screen and write path
```

## Licence

Proprietary. © Justin Holt.
