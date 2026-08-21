<?php

namespace justinholtweb\airwave\models;

use justinholtweb\airwave\elements\Channel;

/**
 * What each edition allows.
 *
 * Pure and static, taking `$isPro` rather than reaching for the plugin, so the boundary can be
 * tested without an application and read in one place as the answer to "what exactly does Pro
 * buy".
 *
 * Two things enforce it, and they do different jobs:
 *
 * - The CP, the importer and the console **refuse** what Lite cannot do, so an author is told
 *   rather than quietly given something else.
 * - {@see self::applyToDisplay()} and {@see self::applyToChannel()} **downgrade** on the way out,
 *   so a site whose licence lapsed keeps serving feeds with the Lite feature set instead of
 *   breaking. Stored config is untouched, so renewing restores exactly what was there.
 */
class Edition
{
    public const LITE_MAX_SOURCES = 3;
    public const LITE_MAX_CHANNELS = 1;

    /** Lite publishes RSS. Atom and JSON Feed are Pro. */
    public const LITE_FORMATS = [Channel::FORMAT_RSS];

    public static function maxSources(bool $isPro): ?int
    {
        return $isPro ? null : self::LITE_MAX_SOURCES;
    }

    public static function maxChannels(bool $isPro): ?int
    {
        return $isPro ? null : self::LITE_MAX_CHANNELS;
    }

    /** Keyword, category, author and date rules over incoming items. */
    public static function allowsFilters(bool $isPro): bool
    {
        return $isPro;
    }

    /** Turning feed items into entries. */
    public static function allowsImporting(bool $isPro): bool
    {
        return $isPro;
    }

    /** Rendering `content:encoded` rather than the summary. */
    public static function allowsFullContent(bool $isPro): bool
    {
        return $isPro;
    }

    /** Appending UTM or affiliate parameters to outgoing item links. */
    public static function allowsLinkRewriting(bool $isPro): bool
    {
        return $isPro;
    }

    /** Reading several feeds as one stream. */
    public static function allowsMerging(bool $isPro): bool
    {
        return $isPro;
    }

    /** A refresh interval per feed, rather than the site-wide default. */
    public static function allowsPerSourceIntervals(bool $isPro): bool
    {
        return $isPro;
    }

    /** Object templates for a published channel's item fields. */
    public static function allowsChannelTemplates(bool $isPro): bool
    {
        return $isPro;
    }

    public static function allowsFormat(string $format, bool $isPro): bool
    {
        return $isPro || in_array($format, self::LITE_FORMATS, true);
    }

    /**
     * @return string[]
     */
    public static function formats(bool $isPro): array
    {
        return $isPro ? Channel::FORMATS : self::LITE_FORMATS;
    }

    /**
     * Returns a copy of the display options with anything this edition cannot serve turned off.
     *
     * A dropped option is turned *off*, never reinterpreted — a feed showing summaries is a feed;
     * a feed showing something else is a bug report.
     */
    public static function applyToDisplay(DisplayOptions $options, bool $isPro): DisplayOptions
    {
        if ($isPro) {
            return $options;
        }

        $downgraded = DisplayOptions::fromArray($options->toArray());
        $downgraded->content = DisplayOptions::CONTENT_SUMMARY;
        $downgraded->rewriteLinks = false;

        return $downgraded;
    }

    /**
     * Returns the format a channel may actually be served as.
     *
     * A lapsed licence serves RSS instead of Atom rather than a 404: a feed URL that has been in
     * readers' subscription lists for a year should keep answering with *a* feed.
     */
    public static function applyToChannel(string $format, bool $isPro): string
    {
        return self::allowsFormat($format, $isPro) ? $format : Channel::FORMAT_RSS;
    }
}
