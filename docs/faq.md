---
title: FAQ
slug: faq
order: 50
summary: Common questions about aggregating and publishing RSS, Atom and JSON Feed in Craft.
---

## Is Airwave free?

Lite is, and it is not a trial — three feeds, one published channel, RSS output, the renderer, the
reference tag, the Feed field and the console commands, for nothing. Pro is a one-off **$59** with a
**$29/year** renewal.

## What is the difference between Lite and Pro?

Lite covers reading feeds and publishing one. Pro removes the feed and channel limits, adds Atom and
JSON Feed output, and adds the things you need once you are curating rather than embedding:
filters, importing items as entries, full article content, merged multi-feed streams, per-feed
refresh intervals, link rewriting and object templates. See
[Installation](installation#editions) for the table.

## What happens if my licence lapses?

Nothing breaks and nothing is deleted. Airwave downgrades on the way out: full content serves as
summaries, an Atom channel serves RSS, a merged stream serves its first feed. The control panel
refuses to *save* new Pro configuration, but never 404s a URL that has been in subscription lists
for a year. Renewing restores exactly what was there.

## Does it read Atom and JSON Feed, or only RSS?

All of them — RSS 2.0, RSS 1.0 (RDF), Atom 1.0 and JSON Feed — and it sniffs the document rather
than trusting the file extension or the `Content-Type`, because feeds are served as `text/html`
more often than anyone would like. Everything becomes one internal item shape, so nothing
downstream ever asks which dialect it came from.

## Can it publish a feed as well as read one?

Yes, and that is the half most feed plugins do not have. A **channel** publishes RSS, Atom or JSON
Feed from any element query — or from the items Airwave has already collected, which is how you
publish a curated feed without writing a template.

## I am moving off WordPress and I miss Feedzy's shortcode.

Paste `{airwave:craft-news:render}` into any rich-text field. Craft parses reference tags before
the value reaches your template, so it works in CKEditor, Redactor or a plain HTML field with no
template changes and no per-editor integration.

## Will a slow or broken feed slow down my site?

No. A feed that has never been fetched is read inline once, so the first page load is not empty;
after that a stale feed queues a background job and the page renders from what is stored. And if a
fetch fails, the stored items stay and keep rendering — a failed refresh never empties a feed.

## Is it safe to let editors add feed URLs?

That is what the SSRF guard is for. Airwave resolves every host and refuses private and reserved
addresses, and re-validates **every hop** of a redirect chain, because an open redirect on a public
host walks straight past a check that only looks at the URL somebody typed. Allowing private
network fetches is a site-wide setting that is off by default.

## Is feed HTML sanitised?

Yes, and there is no way around it. Every item goes through HTML Purifier before it can reach a
page, and aggregated items are purified again on the way out of a channel — republishing somebody
else's markup under your own domain deserves the second pass. Images, links, headings, lists and
video embeds are allowed; forms, style blocks, scripts and colliding `id` attributes are not.

## Can I import feed items as real entries?

On Pro, yes. Map item fields onto your entry type's fields with tokens or Twig, preview what would
happen, and run it. Nothing is ever imported twice: dedupe is by the record of *this item became
that entry*, so it survives renaming, moving or editing the entry — and deleting the entry does not
invite the article back.

## Does importing put things straight on my live site?

Only if you tell it to. New entries can arrive disabled, importing is a separate permission from
adding a feed, and **Show what would be imported** writes nothing.

## Can I change the markup?

Completely. Put your own template at `templates/_airwave/items.twig` and Airwave uses it, or skip
the renderer and loop over `craft.airwave.items()` yourself. The shipped stylesheet sets layout and
nothing else — no colours, no fonts — so an unmodified feed still looks like the page it is on.

## Where are the items stored?

In their own table, not as elements. A feed republished hourly for a year is around 9,000 rows that
nobody edits, searches, relates, revises or restores; giving each one an element row, a per-site
row and a search index entry would buy nothing and cost a great deal. Items that *become* entries
get real elements — that is what importing is.

## How often do feeds refresh?

Hourly by default, per feed on Pro. You can turn front-end refreshing off entirely and drive it
from cron with `php craft airwave/feeds/refresh --due`, which respects each feed's own interval
rather than replacing it with the crontab's.

## Does it work on a multi-site install?

Yes. Feeds and channels are not localized — a feed is the same feed on every site — but a channel
carries a **target site**, which is the site whose content it broadcasts. That is a different thing
from the site it is served from, and it is the setting to check if a second site's feed shows the
wrong content.

## What are the requirements?

Craft CMS 5.3+, PHP 8.2+, and the standard `simplexml`, `json`, `libxml` and `mbstring` extensions.
There is no feed-parsing library to install.
