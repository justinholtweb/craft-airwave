---
title: Installation
slug: installation
order: 10
summary: Requirements, install, your first feed, and what Lite and Pro each cover.
---

## Requirements

- Craft CMS 5.3 or later
- PHP 8.2 or later
- The `simplexml`, `json`, `libxml` and `mbstring` extensions — all standard

There is no feed-parsing dependency to install. Airwave reads RSS, Atom and JSON Feed with PHP's
own parsers, and purifies markup with the HTML Purifier that ships with Craft.

## Install

```sh
composer require justinholtweb/craft-airwave
php craft plugin/install airwave
```

Or find **Airwave** in the Craft Plugin Store and install it from there.

## Nothing happens until you add a feed

Installing Airwave changes nothing about your site. It adds two element types — **Feeds** and
**Channels** — and neither has anything in it until you say so. No routes are claimed, no templates
are overridden, and nothing is written to your sections.

## Your first feed

1. Go to **Airwave → Feeds → New feed**.
2. Paste a feed URL. If you only have the site address, paste that instead and press
   **Find the feed** — Airwave reads the page and offers whatever it advertises.
3. Give it a **handle**. This is what you will type in templates, so `craft-news` beats `feed1`.
4. Save. Airwave fetches it immediately and shows you what it found.

Then put it on a page:

```twig
{{ craft.airwave.render('craft-news', { limit: 5 }) }}
```

See [Usage](usage) for the rest — layouts, merged streams, the Feed field, and writing your own
markup.

## Your first channel

1. Go to **Airwave → Channels → New channel**.
2. Choose **Your own content**, pick a section, and set a URI like `blog.rss`.
3. Save, then visit that URI. That is your feed.

Add the discovery tag to your `<head>` so readers and browsers can find it:

```twig
{{ craft.airwave.autoDiscovery() }}
```

## Editions

Lite is free and is not a trial. Pro is a one-off **$59** with a **$29/year** renewal.

| | Lite | Pro |
|---|---|---|
| **Price** | **Free** | **$59**, $29/year renewal |
| Feeds | 3 | Unlimited |
| Published channels | 1 | Unlimited |
| Output formats | RSS 2.0 | RSS 2.0, Atom, JSON Feed |
| Display, caching, scheduled refresh | ✅ | ✅ |
| Reference tags and the Feed field | ✅ | ✅ |
| Feed discovery, SSRF guard, HTML purification | ✅ | ✅ |
| Console commands | ✅ | ✅ |
| **Keyword, category, author and date filters** | — | ✅ |
| **Import items as entries** | — | ✅ |
| **Full article content** | — | ✅ |
| **Merged multi-feed streams** | — | ✅ |
| **Per-feed refresh intervals** | — | ✅ |
| **Link rewriting** — UTM, affiliate | — | ✅ |
| **Object templates for published items** | — | ✅ |

## What happens if a licence lapses

Nothing breaks and nothing is deleted. Airwave **downgrades on the way out and refuses on the way
in**: the control panel will not let you *save* an Atom channel on Lite, but a channel already
saved as Atom serves RSS rather than returning a 404, because that URL has been in people's
subscription lists for a year. Full content serves as summaries; a merged stream serves its first
feed.

Stored configuration is untouched, so renewing restores exactly what was there.

## Upgrading from a feed plugin

If you have moved a site off WordPress and miss Feedzy's shortcode, Airwave's reference tag does
the same job in any rich-text field, with no template changes:

```
{airwave:craft-news:render}
```

See [Usage](usage#in-rich-text).
