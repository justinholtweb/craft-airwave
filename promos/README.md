# Plugin Store promo images

Marketing images for the Airwave listing on the Craft Plugin Store, rendered in the same theme as
the plugin's marketing page at
[justinholt.com/plugins/craft-airwave](https://justinholt.com/plugins/craft-airwave).

## Building

```bash
./build.sh          # all slides
./build.sh "2 5"    # just slides 2 and 5
```

Output lands in `out/` as `airwave-promo-N.png`, 1920×1080 (rendered at 2× in headless Chrome, then
downsampled so the type stays crisp).

## Slides

| # | Slide | Shows |
|---|-------|-------|
| 1 | Cover — name, tagline, app icon, price | — |
| 2 | One item shape, both directions | the whole pitch: in from four dialects, out to four places |
| 3 | One line, or none at all | the Twig tag, the reference tag, the merged stream |
| 4 | Filtered on the way in | four items, two kept and two rejected, with reasons |
| 5 | Feed items as real entries | the import field mapping, and the dedupe promise |
| 6 | Publish your own | four channels, including one built from collected items |
| 7 | Every feed, and its health | the console |

## Theme

| | |
|---|---|
| Accent | `#5B2FA8`, the icon tile |
| Accent light | `#9B6DF3` |
| Signal | `#4CC9F0` — the outbound half of every gradient |
| Display face | Jersey 20 |
| Body face | Inter |

Both faces are self-hosted in `assets/` and inlined as base64 by `build.sh` before rendering —
Chrome's `file://` origin rules block them otherwise, and the deck silently falls back to system
sans. `fonts.css` is generated, not edited.

`assets/watermark.svg` is the mast **without the tile**. At watermark scale a filled background
reads as a hard-edged grey box across every slide, so the silhouette is strokes only.
