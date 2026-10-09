---
title: Configuration
slug: configuration
order: 20
summary: Feed options, refresh scheduling, filters, channels, settings and permissions.
---

## Feed options

Every feed has its own settings, and most of them override a site-wide default rather than
replacing it.

| | |
|---|---|
| **Feed URL** | The document to fetch. Paste a site address instead and press **Find the feed**. |
| **Handle** | What you type in templates and reference tags. |
| **Refresh interval** | How often this feed is refetched. Hourly by default. Per-feed intervals are Pro; Lite uses the site-wide interval. |
| **Items to keep** | The prune ceiling. Older items past this count are removed — except imported ones. |
| **Maximum age** | Drop items older than N days. Undated items are always *kept*, never dropped. |
| **Request headers** | For private feeds. Values are run through `App::parseEnv()`. |
| **Link rewriting** | Append UTM parameters or an affiliate tag to outgoing links (Pro). |

### Private feeds

There is deliberately no password field. Add a request header on the feed instead:

```
Authorization: $FEED_TOKEN
```

The `$FEED_TOKEN` is resolved from your environment at fetch time, so the token stays in `.env`
rather than in the database — and in every backup of it.

Only **admins** can add or change a header that references the environment (`$VAR`, `${VAR}` or an
`@alias`). The header is sent to whatever server the feed URL names, so anyone who could write one
could point a feed at their own server and read any variable in `.env`. Other users can still edit
the feed, keep headers an admin set, and add plain ones. Connection headers such as `Host` and
`Content-Length` can't be set at all.

## Refreshing

A feed that has **never** been fetched is read inline the first time something asks for it, so a
template does not render an empty box on the first page load. A feed that is merely stale queues a
job, so a slow publisher can never become a slow page.

To drive refreshes from cron instead, turn off **Refresh on the front end** in the settings and
run:

```sh
php craft airwave/feeds/refresh --due
```

`--due` fetches only the feeds whose own interval has elapsed, so the schedule stays per feed
rather than becoming whatever the crontab says. A sensible crontab entry is every fifteen minutes;
feeds set to refresh daily still refresh daily.

### A failed refresh never empties a feed

If a fetch fails — the host is down, the document is malformed, the certificate expired — the items
already stored stay exactly where they are and keep rendering. Only the health indicator on the
feed changes. A publisher's bad afternoon should not blank a section of your homepage.

## Failing-feed alerts

Because a failed refresh never empties a feed, a feed can be broken for a month without anybody
noticing. Alerts are how somebody notices: **one** email (and, optionally, one Slack, Teams or JSON
webhook post) when a feed gets into trouble, and one when it is refreshing again — never one per
failed refresh. Set them up under **Settings → Airwave → Alerts**.

| Incident | Opens when | Closes when |
|---|---|---|
| **Feed failing** | *Failed refreshes in a row* (default 3) have failed with no success in between | The next successful refresh (a `304 Not Modified` counts) |
| **Feed not refreshing** | No successful refresh for *N hours*, or twice the feed's interval if that is longer. Off (0) by default | A successful refresh |

Each feed has its own latch per incident, so ten failing feeds are ten alerts, and one feed failing
fifty times is one. An incident that reopens inside the **quiet period** after its recovery
(default 60 minutes) is reported when the period ends, if it is still happening, so a flapping
feed cannot fill an inbox. A send that fails is retried on the next check rather than lost.

Failures are counted at the end of every refresh, whoever started it — a page view, the queue, the
CP or cron. A feed that has stopped refreshing *altogether* can only be noticed by a check, which
`airwave/feeds/refresh` runs when it finishes; `php craft airwave/alerts/check` runs it on its own.
Leave **Feed not refreshing** off if your feeds only refresh when somebody views them.

| Setting | |
|---|---|
| **Email alerts to** | Addresses separated by commas, or an environment variable. Empty means no email. |
| **Slack or Teams webhook URL** | Optional. Keep it in an environment variable: the URL is the credential. |
| **Webhook format** | Slack, Microsoft Teams (Adaptive Card), or plain JSON for your own receiver. |
| **Webhook signing secret** | Optional. Adds `X-Airwave-Timestamp` and `X-Airwave-Signature: sha256=<hmac of "timestamp.body">`. |
| **Send a test alert** | Sends to the *saved* settings. Admins only. |

The webhook only goes to public addresses: the host is resolved and every address checked, the
connection is pinned to those addresses, and redirects are never followed. `allowPrivateAlertWebhookHosts`
in `config/airwave.php` lets it reach a self-hosted chat server on your network; it is not tied to
**Allow private network fetches**, which is about feed hosts. Messages say which feed, what
happened and where to look; the feed's URL is left out (it can carry a token), and error text is
stripped of query strings, request-header secrets and anything shaped like a credential.

The `justinholtweb\airwave\services\Alerts::EVENT_BEFORE_NOTIFY` event lets a module reword an alert,
or swallow it by setting `$event->isValid = false`.

### Feed health widget

Add **Feed health** to the Dashboard for a tile with the library's totals and every feed that is
failing or has an open alert, with its last successful refresh and item count. It reads the same
latch the alerts come from, and needs the *View feeds and items* permission.

## Filters (Pro)

Filters are rules about what is allowed in, and they run **on the way in**. An item that was never
wanted is never stored, and therefore can never be imported into an entry by accident.

| Filter | Matches on |
|---|---|
| **Keywords — include** | Title and summary. An item must match at least one. |
| **Keywords — exclude** | Title and summary. Any match rejects the item. |
| **Categories** | The item's own categories or tags. |
| **Authors** | The item's author. |
| **Maximum age** | `publishedAt`, when there is one. |

Because filters run on the way in, changing the rules only affects future items. To apply new rules
to what you already have, use **Reapply filters** on the feed — it is a separate, explicit button
because it deletes stored items.

## Channels

A channel is a URI on your site plus enough configuration to answer it.

| | |
|---|---|
| **Source** | Your own content (an element query) or the items Airwave has collected. |
| **Format** | RSS 2.0 on Lite; RSS, Atom or JSON Feed on Pro. |
| **URI** | Where it is served, e.g. `blog.rss`. Airwave routes this itself. |
| **Site** | Which site's content the channel broadcasts — not the same thing as which site it is served from. |
| **Item limit** | How many items the document carries. |
| **Cache duration** | How long the built document is cached, and what `Cache-Control` says. |

For **your own content**, Airwave works out the mapping: the entry title, its URL, a summary or
excerpt field, the author, and the first image field it finds. On Pro you can replace any of those
with an object template:

```twig
{{ object.body }}
{{ object.author.fullName }}
{{ object.featureImage.one().url ?? '' }}
```

Object templates are Twig, and a channel is served on a public URL, so **only admins can change
them** — the same rule Craft applies to its own Twig-bearing settings. Other users with the channel
permission can edit everything else, and their saves keep the templates as they are. If you turn on
Craft's [`enableTwigSandbox`](https://craftcms.com/docs/5.x/reference/config/general.html#enabletwigsandbox)
(Craft 5.9+), Airwave renders these templates in the sandbox too.

The **Order** setting takes attributes (`postDate`, `expiryDate`, `dateCreated`, `dateUpdated`,
`title`, `slug`, `id`) or custom field handles, each optionally followed by `asc` or `desc` and
separated by commas — `postDate desc, title`. A channel only ever broadcasts live entries.

## Import mapping (Pro)

On a feed's **Import** tab, choose a section and entry type, decide whether new entries arrive
enabled or disabled, and map the parts of an item onto your fields — with a token:

`summary` · `content` · `url` · `author` · `image` · `categories` · `guid` · `domain` ·
`publishedAt`

— or with a Twig template, for anything a token does not cover:

```twig
{{ item.summary }} <p><a href="{{ item.url }}">Read the original on {{ item.domain }}</a></p>
```

Anyone with the import permission can map tokens; only admins can add or change a template.

Categories map onto a **Tags** field by creating tags, and onto a **Categories** field by matching
existing ones. An aggregator inventing branches in your category tree is not a favour.

## Settings

Most settings are defaults that a single feed can override. Two are deliberately site-wide, because
they are safety rails rather than preferences.

### Allow private network fetches

Off, and it should stay off. A feed URL is user input on any site where a non-admin can add one,
and `http://169.254.169.254/…` is the standard way that ends badly. Airwave resolves every host and
refuses private and reserved addresses — and re-checks **every hop** of a redirect chain, because
an open redirect on a public host walks straight past a check that only looks at the URL somebody
typed.

Turn this on only when you are subscribing to a feed on your own network.

### Clean incoming HTML

On. Every item goes through HTML Purifier before it can reach a page. Airwave's configuration
allows images, links, headings, lists and video embeds, and blocks forms, style blocks, scripts and
`id` attributes that would collide with your own page.

Point it at your own `config/htmlpurifier/*.json` if you want something else.

### Other settings

| | |
|---|---|
| **Refresh on the front end** | Whether a stale feed may queue its own refresh job. Off if you drive refreshes from cron. |
| **Default refresh interval** | The starting interval for a new feed. |
| **Default items to keep** | The starting prune ceiling for a new feed. |
| **Request timeout** and **Maximum response size** | Fetch ceilings. A feed that will not answer, or answers with 90MB, is abandoned rather than absorbed. |

## Permissions

| | |
|---|---|
| View feeds and items | Read the CP screens |
| Add and edit feeds | Subscribe and configure |
| Delete feeds and items | |
| Refresh feeds | Fetch on demand |
| **Import items as entries** | Separate on purpose — importing writes into a live section, which is a larger thing than subscribing to a URL |
| Publish and edit channels | |
