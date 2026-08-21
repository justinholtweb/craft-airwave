# Airwave documentation

These files are the source for the documentation published at
[justinholt.com/plugins/craft-airwave/docs](https://justinholt.com/plugins/craft-airwave/docs).
`pluginsite/docs/sync` reads this directory, so every file that should appear on the site needs
YAML front matter with `title`, `slug`, `order` and `summary`. A file without it is skipped — which
is how this README stays off the site.

| File | Page |
|---|---|
| `installation.md` | Requirements, install, first feed, editions |
| `configuration.md` | Feed options, refreshing, filters, channels, settings, permissions |
| `usage.md` | Rendering, reference tags, the Feed field, publishing, importing, console |
| `troubleshooting.md` | What to check when a feed, a channel or an import misbehaves |
| `faq.md` | The questions worth answering before installing it |

For a shorter tour, see the project [README](../README.md). For how the plugin is put together —
and the list of things that went wrong while building it — see [CLAUDE.md](../CLAUDE.md).
