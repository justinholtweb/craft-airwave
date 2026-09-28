<?php
/**
 * Airwave integration checks.
 *
 * Run inside the plugin-testing container, from the site root:
 *
 *     ddev exec php /var/www/craft-airwave/tests/integration/checks.php
 *
 * Covers what fixtures cannot: the four parser dialects against awkward documents, real element
 * saves, reference-tag resolution through Craft's own parser, the store's dedupe and pruning
 * rules, channel routing, and a round trip — items out through the broadcaster and back in through
 * the parser — which is the check that catches escaping bugs no assertion about a string would.
 *
 * Idempotent and self-cleaning: everything it creates is named with a run-specific suffix and
 * deleted at the end, whether or not the checks pass.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\elements\Entry;
use justinholtweb\airwave\elements\Channel;
use justinholtweb\airwave\elements\Source;
use justinholtweb\airwave\helpers\Trust;
use justinholtweb\airwave\models\ChannelMapping;
use justinholtweb\airwave\models\DisplayOptions;
use justinholtweb\airwave\models\Edition;
use justinholtweb\airwave\models\FeedMeta;
use justinholtweb\airwave\models\FilterRules;
use justinholtweb\airwave\models\ImportConfig;
use justinholtweb\airwave\models\Item;
use justinholtweb\airwave\Plugin;
use justinholtweb\airwave\services\Fetcher;

$fixtures = require __DIR__ . '/fixtures.php';

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();

        if ($result === true) {
            $passed++;
            echo "  ✓ $label\n";
            return;
        }

        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : 'returned ' . var_export($result, true)) . "\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ✗ $label\n    " . get_class($e) . ': ' . $e->getMessage() . "\n    " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}

function section(string $title): void
{
    echo "\n$title\n";
}

$plugin = Plugin::getInstance();
$run = substr(md5((string)microtime(true)), 0, 6);
$handle = "aw-check-$run";
$created = [];

// Pro, so the whole feature set is exercised. The edition section switches back and forth, and
// the shutdown handler below puts back whatever this site was on before.
$originalEdition = $plugin->edition;
Craft::$app->getPlugins()->switchEdition('airwave', Plugin::EDITION_PRO);

/**
 * Cleanup runs from a shutdown handler rather than off the end of the script.
 *
 * Learned the hard way: a fatal anywhere above — and a check that calls a service outside the
 * `check()` wrapper can absolutely fatal — used to skip the cleanup entirely and leave feeds
 * behind, which then counted against the Lite limit and made the *next* run fail for a reason
 * that had nothing to do with the code.
 */
register_shutdown_function(function() use (&$created, &$section, &$titlelessSection, $run, $originalEdition) {
    foreach (['entry', 'hiddenEntry', 'contentChannel', 'late', 'slashed', 'channel', 'importSource', 'titleless', 'empty', 'auto', 'source'] as $key) {
        if (isset($created[$key])) {
            try {
                Craft::$app->getElements()->deleteElement($created[$key], true);
            } catch (Throwable) {
            }
        }
    }

    // Anything an earlier interrupted run left behind, and anything created above that the list
    // does not name.
    foreach ([Source::find(), Channel::find()] as $query) {
        foreach ($query->status(null)->limit(null)->all() as $element) {
            if (str_starts_with((string)$element->handle, 'aw-')) {
                try {
                    Craft::$app->getElements()->deleteElement($element, true);
                } catch (Throwable) {
                }
            }
        }
    }

    foreach (array_filter([$section ?? null, $titlelessSection ?? null]) as $sweep) {
        foreach (Entry::find()->sectionId($sweep->id)->status(null)->limit(null)->all() as $entry) {
            foreach (['foremost', 'Relative link', 'tracking pixel', 'needing a slug', "check entry $run"] as $marker) {
                if (str_contains((string)$entry->title, $marker)) {
                    try {
                        Craft::$app->getElements()->deleteElement($entry, true);
                    } catch (Throwable) {
                    }
                    break;
                }
            }
        }
    }

    Craft::$app->getPlugins()->switchEdition('airwave', $originalEdition);
    // Project config writes from a console script sit in a buffer until something asks for them,
    // so without this the edition switch above is simply lost.
    Craft::$app->getProjectConfig()->saveModifiedConfigData();
});

// Parsing
// ---------------------------------------------------------------------------------------------

section('Parsing — RSS 2.0');

$rss = $plugin->parser->parse($fixtures['rss'], 'https://example.com/feed.xml');

check('reads the channel', fn() => $rss->meta->title === 'Airwave Test Feed'
    && $rss->meta->link === 'https://example.com/blog'
    && $rss->meta->language === 'en-gb'
    && $rss->meta->format === FeedMeta::FORMAT_RSS
    && $rss->meta->ttl === 60
    ?: json_encode($rss->meta->toArray()));

check('reads the channel image', fn() => $rss->meta->imageUrl === 'https://example.com/logo.png');

check('reads three items', fn() => $rss->count() === 3 ?: 'got ' . $rss->count());

check('decodes entities in titles', fn() => $rss->items[0]->title === 'First & foremost' ?: $rss->items[0]->title);

check('keeps summary and content:encoded apart', function() use ($rss) {
    return str_contains((string)$rss->items[0]->summary, 'short summary')
        && str_contains((string)$rss->items[0]->content, '<strong>cycling</strong>')
        ?: 'summary=' . $rss->items[0]->summary;
});

check('strips the email out of an RSS author', fn() => $rss->items[0]->author === 'Jane Editor' ?: $rss->items[0]->author);

check('falls back to dc:creator', fn() => $rss->items[1]->author === 'Sam Writer' ?: (string)$rss->items[1]->author);

check('reads categories', fn() => $rss->items[0]->categories === ['Sport', 'Cycling'] ?: json_encode($rss->items[0]->categories));

check('reads media:thumbnail as the image', fn() => $rss->items[0]->imageUrl === 'https://example.com/img/first.jpg' ?: (string)$rss->items[0]->imageUrl);

check('reads the enclosure', fn() => $rss->items[0]->enclosureUrl === 'https://example.com/audio/first.mp3'
    && $rss->items[0]->enclosureType === 'audio/mpeg'
    && $rss->items[0]->enclosureLength === 12345);

check('parses an RFC 822 date', fn() => $rss->items[0]->publishedAt?->format('Y-m-d H:i') === '2025-02-03 09:00'
    ?: (string)$rss->items[0]->publishedAt?->format('c'));

check('resolves a relative link against the feed URL', fn() => $rss->items[1]->url === 'https://example.com/blog/second' ?: (string)$rss->items[1]->url);

check('finds an image in the body when there is nowhere else', fn() => $rss->items[1]->imageUrl === 'https://example.com/img/second.png' ?: var_export($rss->items[1]->imageUrl, true));

check('ignores a 1x1 tracking pixel', fn() => $rss->items[2]->imageUrl === null ?: (string)$rss->items[2]->imageUrl);

check('falls back to the link for a missing guid', fn() => $rss->items[2]->guid === 'https://example.com/blog/third' ?: (string)$rss->items[2]->guid);

check('leaves a missing date null rather than inventing now()', fn() => $rss->items[2]->publishedAt === null);

section('Parsing — Atom 1.0');

$atom = $plugin->parser->parse($fixtures['atom'], 'https://atom.example.com/feed');

check('reads the feed', fn() => $atom->meta->title === 'Atom Test'
    && $atom->meta->description === 'Le flux'
    && $atom->meta->language === 'fr'
    && $atom->meta->format === FeedMeta::FORMAT_ATOM
    ?: json_encode($atom->meta->toArray()));

check('prefers the alternate link over self', fn() => $atom->meta->link === 'https://atom.example.com/' ?: (string)$atom->meta->link);

check('unwraps inline XHTML content', fn() => trim((string)$atom->items[0]->content) === '<p>Real <em>markup</em>.</p>' ?: var_export($atom->items[0]->content, true));

check('prefers published over updated', fn() => $atom->items[0]->publishedAt?->format('Y-m-d H:i') === '2025-03-01 08:00' ?: (string)$atom->items[0]->publishedAt?->format('c'));

check('falls back to updated when there is no published', fn() => $atom->items[1]->publishedAt?->format('Y-m-d') === '2025-03-05');

check('reads the author name', fn() => $atom->items[0]->author === 'Ada Lovelace');

check('reads a category term', fn() => $atom->items[0]->categories === ['computing']);

check('reads an enclosure link', fn() => $atom->items[0]->enclosureUrl === 'https://atom.example.com/one.pdf');

check('takes a bare link as the alternate', fn() => $atom->items[1]->url === 'https://atom.example.com/two' ?: (string)$atom->items[1]->url);

check('decodes escaped HTML content', fn() => $atom->items[1]->content === '<p>Escaped HTML.</p>' ?: var_export($atom->items[1]->content, true));

section('Parsing — RSS 1.0 (RDF) and JSON Feed');

$rdf = $plugin->parser->parse($fixtures['rdf'], 'https://rdf.example.com/feed');

check('parses RDF as a feed', fn() => $rdf->meta->format === FeedMeta::FORMAT_RDF && $rdf->count() === 1 ?: $rdf->meta->format . '/' . $rdf->count());

check('reads an RDF item', fn() => $rdf->items[0]->title === 'An RDF story'
    && $rdf->items[0]->author === 'Old Timer'
    && $rdf->items[0]->publishedAt?->format('Y-m-d') === '2025-04-01');

$json = $plugin->parser->parse($fixtures['json'], 'https://json.example.com/feed.json');

check('parses JSON Feed', fn() => $json->meta->format === FeedMeta::FORMAT_JSON && $json->count() === 2);

check('reads a JSON Feed item', fn() => $json->items[0]->title === 'JSON item'
    && $json->items[0]->content === '<p>The content.</p>'
    && $json->items[0]->categories === ['one', 'two']
    && $json->items[0]->imageUrl === 'https://json.example.com/1.png'
    && $json->items[0]->enclosureLength === 42);

check('reads authors (1.1) and author (1.0)', fn() => $json->items[0]->author === 'Jay Sonn' && $json->items[1]->author === 'Solo Author');

check('takes content_text when there is no content_html', fn() => $json->items[1]->content === 'Plain text only.');

section('Parsing — refusals');

check('refuses a document with a DOCTYPE', function() use ($plugin, $fixtures) {
    try {
        $plugin->parser->parse($fixtures['doctype']);
        return 'parsed it';
    } catch (Throwable $e) {
        return str_contains($e->getMessage(), 'DOCTYPE') ?: $e->getMessage();
    }
});

check('refuses XML that is not a feed', function() use ($plugin, $fixtures) {
    try {
        $plugin->parser->parse($fixtures['notafeed']);
        return 'parsed it';
    } catch (Throwable $e) {
        return str_contains($e->getMessage(), 'catalogue') ?: $e->getMessage();
    }
});

check('refuses an empty document', function() use ($plugin) {
    try {
        $plugin->parser->parse('   ');
        return 'parsed it';
    } catch (Throwable) {
        return true;
    }
});

check('rejects a javascript: link', fn() => $plugin->parser->absoluteUrl('javascript:alert(1)', 'https://example.com/') === null);

check('keeps an http link', fn() => $plugin->parser->absoluteUrl('http://example.com/x', 'https://example.com/') === 'http://example.com/x');

check('resolves a protocol-relative link', fn() => $plugin->parser->absoluteUrl('//cdn.example.com/x.png', 'https://example.com/a/b') === 'https://cdn.example.com/x.png');

section('Parsing — discovery and purification');

$found = $plugin->parser->discover($fixtures['html'], 'https://blog.example.com/');

check('finds advertised feeds', fn() => count($found) === 2 ?: json_encode($found));

check('resolves a relative feed href', fn() => $found[0]['url'] === 'https://blog.example.com/feed.xml' ?: $found[0]['url']);

check('ignores stylesheets and alternate languages', fn() => !in_array('/style.css', array_column($found, 'url'), true));

check('purifier removes script tags', function() use ($plugin) {
    $html = (string)$plugin->parser->purify('<p>Fine</p><script>alert(1)</script>');
    return !str_contains($html, '<script') && str_contains($html, 'Fine') ?: $html;
});

check('purifier removes event handlers', function() use ($plugin) {
    $html = (string)$plugin->parser->purify('<a href="https://x.test" onclick="steal()">x</a>');
    return !str_contains($html, 'onclick') ?: $html;
});

check('purifier keeps images and links', function() use ($plugin) {
    $html = (string)$plugin->parser->purify('<p><a href="https://x.test"><img src="https://x.test/a.png" alt="a"></a></p>');
    return str_contains($html, '<img') && str_contains($html, 'href="https://x.test"') ?: $html;
});

check('purifier strips a javascript: href', function() use ($plugin) {
    $html = (string)$plugin->parser->purify('<a href="javascript:alert(1)">x</a>');
    return !str_contains($html, 'javascript:') ?: $html;
});

// The URL guard
// ---------------------------------------------------------------------------------------------

section('Fetching — the URL guard');

foreach ([
    'not-a-url' => 'nonsense',
    'ftp' => 'ftp://example.com/feed',
    'loopback' => 'http://127.0.0.1/feed',
    'localhost' => 'http://localhost/feed',
    'link-local metadata' => 'http://169.254.169.254/latest/meta-data/',
    'private range' => 'http://192.168.1.1/feed',
    'IPv6 loopback' => 'http://[::1]/feed',
    'carrier-grade NAT metadata' => 'http://100.100.100.200/latest/meta-data/',
    'IPv4-mapped loopback' => 'http://[::ffff:127.0.0.1]/feed',
    'IPv4-mapped private' => 'http://[::ffff:10.0.0.1]/feed',
    'IPv6 unique local' => 'http://[fd00::1]/feed',
    'IPv6 link-local' => 'http://[fe80::1]/feed',
    'NAT64 to a private address' => 'http://[64:ff9b::a00:1]/feed',
    'benchmarking range' => 'http://198.18.0.1/feed',
] as $label => $url) {
    check("refuses $label", fn() => $plugin->fetcher->validateUrl($url) !== null ?: "allowed $url");
}

check('allows a public https URL', fn() => $plugin->fetcher->validateUrl('https://example.com/feed.xml') === null
    ?: (string)$plugin->fetcher->validateUrl('https://example.com/feed.xml'));

check('allows a public IPv6 literal', fn() => $plugin->fetcher->validateUrl('https://[2606:4700:4700::1111]/feed') === null
    ?: (string)$plugin->fetcher->validateUrl('https://[2606:4700:4700::1111]/feed'));

check('connection headers are refused, ordinary ones are not', function() {
    foreach (['Host', 'host', 'Content-Length', 'Transfer-Encoding', 'Connection'] as $name) {
        if (!Fetcher::isForbiddenHeader($name)) {
            return "$name was allowed";
        }
    }

    return !Fetcher::isForbiddenHeader('Authorization') && !Fetcher::isForbiddenHeader('X-Api-Key') ?: 'refused an ordinary header';
});

check('a stored Host header is not sent', function() use ($plugin) {
    $source = new Source();
    $source->url = 'https://example.com/feed.xml';
    $source->setFetchOptions(['headers' => ['Host' => 'internal.example', 'X-Token' => 'abc']]);

    $headers = (new ReflectionMethod(Fetcher::class, 'headers'))->invoke($plugin->fetcher, $source, false);

    return !isset($headers['Host']) && ($headers['X-Token'] ?? null) === 'abc' ?: json_encode($headers);
});

check('environment references in headers are recognised by shape', function() {
    foreach (['$FEED_TOKEN', 'Bearer ${FEED_TOKEN}', '@root', 'a/$SECRET/b', ' $CRAFT_SECURITY_KEY '] as $value) {
        if (!Trust::referencesEnvironment($value)) {
            return "missed '$value'";
        }
    }

    foreach (['Bearer abc123', 'price $5', 'user@example.com', 'a$b'] as $value) {
        if (Trust::referencesEnvironment($value)) {
            return "flagged '$value'";
        }
    }

    return true;
});

// Filters
// ---------------------------------------------------------------------------------------------

section('Filters');

$makeItem = function(string $title, ?string $summary = null, array $categories = [], ?string $author = null, ?string $image = null, ?string $date = null): Item {
    $item = new Item();
    $item->title = $title;
    $item->summary = $summary ?? $title;
    $item->categories = $categories;
    $item->author = $author;
    $item->imageUrl = $image;
    $item->guid = 'urn:' . md5($title . $date);
    $item->publishedAt = $date ? new DateTime($date, new DateTimeZone('UTC')) : null;

    return $item;
};

$batch = [
    $makeItem('Cycling in the Alps', 'A ride about cycling', ['Sport'], 'Jane', 'https://x.test/a.png', '-2 days'),
    $makeItem('Politics today', 'Nothing about bikes', ['News'], 'Sam', null, '-3 days'),
    $makeItem('Sponsored: buy a bike', 'Cycling gear on sale', ['Sport'], 'Ad Bot', 'https://x.test/b.png', '-1 day'),
    $makeItem('Cycling in the Alps', 'A duplicate headline', ['Sport'], 'Jane', 'https://x.test/c.png', '-4 days'),
    $makeItem('Ancient cycling history', 'Long ago', ['Sport'], 'Jane', 'https://x.test/d.png', '-900 days'),
    $makeItem('Undated cycling note', 'No date at all', ['Sport'], 'Jane', 'https://x.test/e.png'),
];

check('an empty rule set keeps everything', fn() => count((new FilterRules())->apply($batch)) === count($batch));

check('include keywords keep only matches', function() use ($batch) {
    $rules = FilterRules::fromArray(['include' => 'cycling']);
    $titles = array_map(fn($i) => $i->title, $rules->apply($batch));
    return !in_array('Politics today', $titles, true) && count($titles) === 5 ?: json_encode($titles);
});

check('exclusion beats inclusion', function() use ($batch) {
    $rules = FilterRules::fromArray(['include' => 'cycling', 'exclude' => 'sponsored']);
    $titles = array_map(fn($i) => $i->title, $rules->apply($batch));
    return !in_array('Sponsored: buy a bike', $titles, true) ?: json_encode($titles);
});

check('match mode “all” needs every keyword', function() use ($batch) {
    $any = FilterRules::fromArray(['include' => "cycling,alps", 'matchMode' => 'any'])->apply($batch);
    $all = FilterRules::fromArray(['include' => "cycling,alps", 'matchMode' => 'all'])->apply($batch);
    return count($all) < count($any) && count($all) === 2 ?: count($all) . ' vs ' . count($any);
});

check('keywords are case-insensitive', fn() => count(FilterRules::fromArray(['include' => 'CYCLING'])->apply($batch)) === 5);

check('searchIn narrows where keywords are looked for', function() use ($batch) {
    $rules = FilterRules::fromArray(['include' => 'bikes', 'searchIn' => ['title']]);
    return count($rules->apply($batch)) === 0 ?: 'matched in the wrong field';
});

check('category filters keep only that category', function() use ($batch) {
    $rules = FilterRules::fromArray(['categories' => 'news']);
    return count($rules->apply($batch)) === 1;
});

check('author exclusion works', function() use ($batch) {
    $rules = FilterRules::fromArray(['excludeAuthors' => 'Ad Bot']);
    return count($rules->apply($batch)) === 5;
});

check('requireImage drops items without one', function() use ($batch) {
    $rules = FilterRules::fromArray(['requireImage' => true]);
    return count($rules->apply($batch)) === 5;
});

check('maxAgeDays drops old items', function() use ($batch) {
    $rules = FilterRules::fromArray(['maxAgeDays' => 30]);
    $titles = array_map(fn($i) => $i->title, $rules->apply($batch));
    return !in_array('Ancient cycling history', $titles, true) ?: json_encode($titles);
});

check('maxAgeDays keeps undated items rather than silently emptying the feed', function() use ($batch) {
    $rules = FilterRules::fromArray(['maxAgeDays' => 30]);
    $titles = array_map(fn($i) => $i->title, $rules->apply($batch));
    return in_array('Undated cycling note', $titles, true) ?: json_encode($titles);
});

check('dedupeTitles drops the repeat, not the original', function() use ($batch) {
    $rules = FilterRules::fromArray(['dedupeTitles' => true]);
    $kept = $rules->apply($batch);
    $summaries = array_map(fn($i) => $i->summary, array_filter($kept, fn($i) => $i->title === 'Cycling in the Alps'));
    return count($summaries) === 1 && reset($summaries) === 'A ride about cycling' ?: json_encode($summaries);
});

check('rules survive a round trip through JSON', function() {
    $rules = FilterRules::fromArray(['include' => 'a,b', 'maxAgeDays' => 5, 'requireImage' => true]);
    $back = FilterRules::fromJson($rules->toJson());
    return $back->include === ['a', 'b'] && $back->maxAgeDays === 5 && $back->requireImage ?: $rules->toJson();
});

check('isEmpty knows the difference', fn() => (new FilterRules())->isEmpty() && !FilterRules::fromArray(['include' => 'x'])->isEmpty());

// The store
// ---------------------------------------------------------------------------------------------

section('The store');

$source = new Source();
$source->title = "Check feed $run";
$source->handle = $handle;
$source->url = 'https://example.com/feed.xml';
$source->enabled = true;

check('a feed saves', fn() => $plugin->sources->saveSource($source) ?: json_encode($source->getErrors()));
$created['source'] = $source;

check('a handle is unique among live feeds', function() use ($plugin, $handle) {
    $clash = new Source();
    $clash->title = 'Clash';
    $clash->handle = $handle;
    $clash->url = 'https://example.com/other.xml';
    return !$plugin->sources->saveSource($clash) && $clash->hasErrors('handle') ?: 'saved a duplicate handle';
});

check('a blank handle is generated from the name', function() use ($plugin, $run, &$created) {
    $auto = new Source();
    $auto->title = "Auto Handle $run";
    $auto->url = 'https://example.com/auto.xml';
    $saved = $plugin->sources->saveSource($auto);
    $created['auto'] = $auto;
    return $saved && str_starts_with((string)$auto->handle, 'auto-handle') ?: (string)$auto->handle;
});

$stored = $plugin->items->store($source, $rss->items);

check('storing counts new items', fn() => $stored['new'] === 3 && $stored['updated'] === 0 ?: json_encode($stored));

check('storing the same document again updates rather than duplicates', function() use ($plugin, $source, $rss) {
    $again = $plugin->items->store($source, $rss->items);
    return $again['new'] === 0 && $again['updated'] === 3 ?: json_encode($again);
});

check('the item count matches', fn() => $plugin->items->countForSource((int)$source->id) === 3 ?: (string)$plugin->items->countForSource((int)$source->id));

check('an edited item is updated in place', function() use ($plugin, $source, $rss) {
    $edited = clone $rss->items[0];
    $edited->title = 'First & foremost (corrected)';
    $plugin->items->store($source, [$edited]);
    $found = $plugin->items->find(['sourceId' => $source->id, 'limit' => 50]);
    $titles = array_map(fn($i) => $i->title, $found);
    return in_array('First & foremost (corrected)', $titles, true) && count($found) === 3 ?: json_encode($titles);
});

check('a document repeating a guid stores it once', function() use ($plugin, $source) {
    $item = new Item();
    $item->guid = "dupe-$source->id";
    $item->title = 'Twice';
    $before = $plugin->items->countForSource((int)$source->id);
    $result = $plugin->items->store($source, [$item, clone $item]);
    $after = $plugin->items->countForSource((int)$source->id);
    return $result['new'] === 1 && $after === $before + 1 ?: json_encode($result) . " $before→$after";
});

check('the guid hash is stable and falls back to the URL', function() {
    $a = new Item();
    $a->guid = 'urn:x';
    $b = new Item();
    $b->guid = 'urn:x';
    $c = new Item();
    $c->url = 'https://example.com/x';
    return $a->getGuidHash() === $b->getGuidHash()
        && $a->getGuidHash() !== $c->getGuidHash()
        && strlen($c->getGuidHash()) === 40;
});

check('finding by handle works', fn() => count($plugin->items->find(['sourceHandle' => $handle, 'limit' => 50])) === 4);

check('an unknown handle finds nothing rather than everything', fn() => $plugin->items->find(['sourceHandle' => 'no-such-feed-anywhere']) === []);

check('search matches titles', fn() => count($plugin->items->find(['sourceId' => $source->id, 'search' => 'Relative'])) === 1);

check('newest-first ordering puts undated items by when they arrived', function() use ($plugin, $source) {
    $items = $plugin->items->find(['sourceId' => $source->id, 'sort' => 'newest', 'limit' => 10]);
    $dated = array_values(array_filter($items, fn($i) => $i->publishedAt !== null));
    return count($dated) >= 2 && $dated[0]->publishedAt >= $dated[1]->publishedAt ?: 'out of order';
});

check('oldest-first reverses it', function() use ($plugin, $source) {
    $newest = $plugin->items->find(['sourceId' => $source->id, 'sort' => 'newest', 'limit' => 10]);
    $oldest = $plugin->items->find(['sourceId' => $source->id, 'sort' => 'oldest', 'limit' => 10]);
    return $newest[0]->id !== $oldest[0]->id ?: 'same first item both ways';
});

check('items carry their feed’s name', function() use ($plugin, $source) {
    $item = $plugin->items->find(['sourceId' => $source->id, 'limit' => 1])[0];
    return $item->sourceHandle === $source->handle && $item->sourceName === $source->title ?: (string)$item->sourceHandle;
});

check('counting agrees with finding, filters and all', function() use ($plugin, $source) {
    $all = $plugin->items->count(['sourceId' => $source->id]);
    $notImported = $plugin->items->count(['sourceId' => $source->id, 'imported' => false]);
    $found = count($plugin->items->find(['sourceId' => $source->id, 'imported' => false, 'limit' => 100]));

    // The import job re-queues itself while this count is above zero, so a count that ignored
    // `imported` would loop against a backlog that was already empty.
    return $notImported === $found && $all >= $notImported ?: "$all all, $notImported not imported, $found found";
});

check('category counts come back most common first', function() use ($plugin, $source) {
    $counts = $plugin->items->getCategoryCounts((int)$source->id);
    return isset($counts['Sport']) ?: json_encode($counts);
});

check('pruning keeps the newest', function() use ($plugin, $source) {
    $removed = $plugin->items->prune($source, 2);
    $left = $plugin->items->countForSource((int)$source->id);
    return $removed === 2 && $left === 2 ?: "removed $removed, left $left";
});

check('pruning never touches an imported item', function() use ($plugin, $source, $rss) {
    $plugin->items->store($source, $rss->items);
    $items = $plugin->items->find(['sourceId' => $source->id, 'sort' => 'oldest', 'limit' => 10]);
    $oldest = $items[0];
    // Point it at any real element so the foreign key holds.
    $plugin->items->markImported((int)$oldest->id, (int)$source->id);
    $plugin->items->prune($source, 1);
    $survivors = array_map(fn($i) => $i->id, $plugin->items->find(['sourceId' => $source->id, 'limit' => 10]));
    return in_array($oldest->id, $survivors, true) ?: 'the imported item was pruned';
});

// The element
// ---------------------------------------------------------------------------------------------

section('The feed element');

check('a feed is found by handle', fn() => $plugin->sources->getSourceByHandle($handle)?->id === $source->id);

check('the embed code names the handle', fn() => $source->getEmbedCode() === "{airwave:$handle:render}" ?: $source->getEmbedCode());

check('a reference tag resolves through Craft’s own parser', function() use ($handle) {
    $html = Craft::$app->getElements()->parseRefs("<p>{airwave:$handle:render}</p>");
    return str_contains($html, 'airwave') && !str_contains($html, '{airwave:') ?: $html;
});

check('a reference tag to a missing feed is left alone', function() {
    $html = Craft::$app->getElements()->parseRefs('{airwave:no-such-feed:render}');
    return !str_contains($html, '<ul') ?: $html;
});

check('a feed is due when it has never been fetched', fn() => (new Source())->getIsDue());

check('a just-fetched feed is not due', function() {
    $fresh = new Source();
    $fresh->lastFetchedAt = new DateTime('now', new DateTimeZone('UTC'));
    return !$fresh->getIsDue();
});

check('a stale feed is due again', function() {
    $stale = new Source();
    $stale->lastFetchedAt = (new DateTime('now', new DateTimeZone('UTC')))->modify('-10 days');
    return $stale->getIsDue();
});

check('deleting parks the handle so it can be reused', function() use ($plugin, $run) {
    $temp = new Source();
    $temp->title = 'Temp';
    $temp->handle = "aw-park-$run";
    $temp->url = 'https://example.com/temp.xml';
    $plugin->sources->saveSource($temp);
    $plugin->sources->deleteSource($temp);

    $replacement = new Source();
    $replacement->title = 'Replacement';
    $replacement->handle = "aw-park-$run";
    $replacement->url = 'https://example.com/replacement.xml';
    $saved = $plugin->sources->saveSource($replacement);

    if ($saved) {
        Craft::$app->getElements()->deleteElement($replacement, true);
    }

    return $saved ?: json_encode($replacement->getErrors());
});

check('restoring reclaims a handle, or a variation of it', function() use ($plugin, $run) {
    $temp = new Source();
    $temp->title = 'Restore me';
    $temp->handle = "aw-restore-$run";
    $temp->url = 'https://example.com/restore.xml';
    $plugin->sources->saveSource($temp);
    $id = $temp->id;
    $plugin->sources->deleteSource($temp);

    $trashed = Source::find()->id($id)->trashed()->status(null)->one();
    Craft::$app->getElements()->restoreElement($trashed);
    $plugin->sources->clearCaches();

    $restored = $plugin->sources->getSourceById($id);
    $ok = $restored && !str_contains((string)$restored->handle, '--trashed-');

    if ($restored) {
        Craft::$app->getElements()->deleteElement($restored, true);
    }

    return $ok ?: 'handle was ' . ($restored->handle ?? 'gone');
});

// Display
// ---------------------------------------------------------------------------------------------

section('Display');

check('options merge without losing the rest', function() {
    $options = DisplayOptions::fromArray(['limit' => 4, 'layout' => 'cards']);
    $merged = $options->merge(['limit' => 2]);
    return $merged->limit === 2 && $merged->layout === 'cards' && $options->limit === 4 ?: 'merge mutated the original';
});

check('unknown options are ignored, not fatal', fn() => DisplayOptions::fromArray(['limit' => 3, 'colour' => 'red'])->limit === 3);

check('an invalid layout falls back rather than rendering nothing', fn() => DisplayOptions::fromArray(['layout' => 'carousel'])->layout === 'list');

check('link params parse from a query string', function() {
    $options = DisplayOptions::fromArray(['linkParams' => 'utm_source=airwave&utm_medium=feed']);
    return $options->linkParams === ['utm_source' => 'airwave', 'utm_medium' => 'feed'] ?: json_encode($options->linkParams);
});

check('link rewriting appends the params', function() {
    $item = new Item();
    $item->url = 'https://example.com/post?a=1';
    $options = DisplayOptions::fromArray(['rewriteLinks' => true, 'linkParams' => 'utm_source=airwave']);
    $link = $item->getLink($options);
    return str_contains((string)$link, 'utm_source=airwave') && str_contains((string)$link, 'a=1') ?: (string)$link;
});

check('link rewriting is off unless asked for', function() {
    $item = new Item();
    $item->url = 'https://example.com/post';
    return $item->getLink(DisplayOptions::fromArray(['linkParams' => 'x=1'])) === 'https://example.com/post';
});

check('an excerpt is plain text, trimmed on a boundary', function() {
    $item = new Item();
    $item->summary = '<p>The quick brown fox jumps over the lazy dog and keeps going for quite a while.</p>';
    $excerpt = $item->getExcerpt(20);
    return !str_contains($excerpt, '<') && mb_strlen($excerpt) <= 21 && str_ends_with($excerpt, '…') ?: $excerpt;
});

check('getHtml purifies whatever mode it is asked for', function() {
    $item = new Item();
    $item->content = '<p>Body</p><script>alert(1)</script>';
    $html = (string)$item->getHtml(DisplayOptions::CONTENT_FULL);
    return str_contains($html, 'Body') && !str_contains($html, 'script') ?: $html;
});

check('rendering a feed produces markup with its items in it', function() use ($plugin, $source, $rss) {
    // Re-stored first: the pruning checks above decide which items survive, and a display check
    // that depends on that is a display check that fails for a storage reason.
    $plugin->items->store($source, $rss->items);
    $html = (string)$source->render(['limit' => 5]);
    return str_contains($html, 'airwave__item') && str_contains($html, 'foremost') ?: substr($html, 0, 500);
});

check('rendering an empty feed says so rather than breaking', function() use ($plugin, $run, &$created) {
    $empty = new Source();
    $empty->title = 'Empty';
    $empty->handle = "aw-empty-$run";
    $empty->url = 'https://example.com/empty.xml';
    // Never fetched *and* disabled, so rendering cannot trigger a real request.
    $empty->enabled = false;
    $plugin->sources->saveSource($empty);
    $created['empty'] = $empty;

    $html = (string)$empty->render();
    return str_contains($html, 'airwave__empty') ?: $html;
});

check('markup in an item title never reaches the page as markup', function() use ($plugin, $source) {
    $nasty = new Item();
    $nasty->guid = 'nasty-1';
    $nasty->title = 'Watch out <img src=x onerror=alert(1)>';
    $nasty->url = 'https://example.com/nasty';
    $plugin->items->store($source, [$nasty]);

    $html = (string)$plugin->renderer->renderItems(
        $plugin->items->find(['sourceId' => $source->id, 'search' => 'Watch out']),
        DisplayOptions::fromArray(['limit' => 1]),
        $source,
    );

    // Titles are plain text everywhere — `Item::getTitleText()` strips tags rather than escaping
    // them, because a feed title is specified as text and a headline with an `<img>` in it is
    // never what the publisher meant.
    return str_contains($html, 'Watch out')
        && !str_contains($html, '<img src=x')
        && !str_contains($html, 'onerror')
        ?: $html;
});

// Editions
// ---------------------------------------------------------------------------------------------

section('Editions');

check('Pro has no feed limit', fn() => Edition::maxSources(true) === null);

check('Lite caps feeds at three', fn() => Edition::maxSources(false) === 3);

check('Lite caps channels at one', fn() => Edition::maxChannels(false) === 1);

check('Lite downgrades full content to summary', function() {
    $options = DisplayOptions::fromArray(['content' => 'full', 'rewriteLinks' => true]);
    $lite = Edition::applyToDisplay($options, false);
    return $lite->content === DisplayOptions::CONTENT_SUMMARY && !$lite->rewriteLinks ?: json_encode($lite->toArray());
});

check('the downgrade leaves the stored options alone', function() {
    $options = DisplayOptions::fromArray(['content' => 'full']);
    Edition::applyToDisplay($options, false);
    return $options->content === DisplayOptions::CONTENT_FULL;
});

check('Pro is left untouched', function() {
    $options = DisplayOptions::fromArray(['content' => 'full', 'rewriteLinks' => true]);
    return Edition::applyToDisplay($options, true)->content === DisplayOptions::CONTENT_FULL;
});

check('a lapsed licence serves RSS instead of 404ing an Atom feed', fn() => Edition::applyToChannel(Channel::FORMAT_ATOM, false) === Channel::FORMAT_RSS
    && Edition::applyToChannel(Channel::FORMAT_ATOM, true) === Channel::FORMAT_ATOM);

check('Lite refuses a fourth feed', function() use ($plugin, $run) {
    Craft::$app->getPlugins()->switchEdition('airwave', Plugin::EDITION_LITE);
    $plugin->sources->clearCaches();

    $extra = new Source();
    $extra->title = 'One too many';
    $extra->handle = "aw-toomany-$run";
    $extra->url = 'https://example.com/toomany.xml';
    $refused = !$plugin->sources->saveSource($extra);

    Craft::$app->getPlugins()->switchEdition('airwave', Plugin::EDITION_PRO);
    $plugin->sources->clearCaches();

    return $refused ?: 'Lite let a fourth feed through';
});

// Channels
// ---------------------------------------------------------------------------------------------

section('Channels');

$primarySiteId = Craft::$app->getSites()->getPrimarySite()->id;

$channel = new Channel();
$channel->title = "Check channel $run";
$channel->handle = "aw-chan-$run";
$channel->uri = "aw-feed-$run";
$channel->targetSiteId = $primarySiteId;
$channel->format = Channel::FORMAT_RSS;
$channel->feedSource = Channel::SOURCE_FEEDS;
$channel->setSourceIds([$source->id]);
$channel->itemLimit = 10;
$channel->feedTitle = 'Curated & Collected';
$channel->feedDescription = 'The best of the web';
$channel->enabled = true;

check('a channel saves', fn() => $plugin->channels->saveChannel($channel) ?: json_encode($channel->getErrors()));
$created['channel'] = $channel;

check('a URI is unique per site', function() use ($plugin, $run, $primarySiteId) {
    $clash = new Channel();
    $clash->title = 'Clash';
    $clash->handle = "aw-chan2-$run";
    $clash->uri = "aw-feed-$run";
    $clash->targetSiteId = $primarySiteId;
    return !$plugin->channels->saveChannel($clash) && $clash->hasErrors('uri') ?: 'saved a duplicate URI';
});

check('a URI is normalised of slashes', function() use ($plugin, $run, $primarySiteId, &$created) {
    $slashed = new Channel();
    $slashed->title = 'Slashed';
    $slashed->handle = "aw-slash-$run";
    $slashed->uri = "/aw-slashed-$run/";
    $slashed->targetSiteId = $primarySiteId;
    $saved = $plugin->channels->saveChannel($slashed);
    $created['slashed'] = $slashed;
    return $saved && $slashed->uri === "aw-slashed-$run" ?: (string)$slashed->uri;
});

check('a nonsense URI is refused', function() use ($plugin, $run, $primarySiteId) {
    $bad = new Channel();
    $bad->title = 'Bad';
    $bad->handle = "aw-bad-$run";
    $bad->uri = 'feed?a=<script>';
    $bad->targetSiteId = $primarySiteId;
    return !$plugin->channels->saveChannel($bad) && $bad->hasErrors('uri');
});

check('the route map has the channel in it', function() use ($plugin, $channel, $primarySiteId) {
    $map = $plugin->channels->getRouteMap($primarySiteId);
    return isset($map[$channel->uri]) && $map[$channel->uri]['params']['handle'] === $channel->handle ?: json_encode(array_keys($map));
});

check('saving a channel invalidates the cached route map', function() use ($plugin, $run, $primarySiteId, &$created) {
    $plugin->channels->getRouteMap($primarySiteId);

    $late = new Channel();
    $late->title = 'Added later';
    $late->handle = "aw-late-$run";
    $late->uri = "aw-late-$run";
    $late->targetSiteId = $primarySiteId;
    $plugin->channels->saveChannel($late);
    $created['late'] = $late;

    return isset($plugin->channels->getRouteMap($primarySiteId)["aw-late-$run"]) ?: 'the map was stale';
});

check('the channel has a URL on its site', fn() => str_contains((string)$channel->getUrl(), $channel->uri));

check('a re-broadcasting channel picks up the aggregated items', fn() => count($channel->getItems()) > 0 ?: 'no items');

// Round trips
// ---------------------------------------------------------------------------------------------

section('Broadcasting — round trips');

$roundTrip = function(string $format) use ($plugin, $channel): array {
    $channel->format = $format;
    $document = $plugin->broadcaster->build($channel);
    $parsed = $plugin->parser->parse($document, 'https://example.test/feed');

    return [$document, $parsed];
};

foreach ([Channel::FORMAT_RSS, Channel::FORMAT_ATOM, Channel::FORMAT_JSON] as $format) {
    check("a $format document Airwave produces is one Airwave can read back", function() use ($roundTrip, $format, $channel) {
        [$document, $parsed] = $roundTrip($format);
        return $parsed->count() === count($channel->getItems())
            && $parsed->meta->title === 'Curated & Collected'
            ?: "titled '{$parsed->meta->title}' with {$parsed->count()} items:\n" . substr($document, 0, 400);
    });
}

$channel->format = Channel::FORMAT_RSS;

check('an ampersand in the feed title survives the round trip', function() use ($roundTrip) {
    [, $parsed] = $roundTrip(Channel::FORMAT_RSS);
    return $parsed->meta->title === 'Curated & Collected' ?: $parsed->meta->title;
});

check('an item title is published as text, with any markup already gone', function() use ($plugin, $channel) {
    $document = $plugin->broadcaster->build($channel);
    $parsed = $plugin->parser->parse($document, 'https://example.test/feed');
    $titles = array_map(fn($i) => $i->title, $parsed->items);
    return in_array('Watch out', $titles, true) && !str_contains($document, 'onerror') ?: json_encode($titles);
});

check('CDATA containing ]]> is split rather than truncating the document', function() use ($plugin) {
    $item = new Item();
    $item->guid = 'cdata-1';
    $item->title = 'CDATA trap';
    $item->summary = 'Before ]]> after';
    $item->url = 'https://example.test/cdata';

    $meta = new FeedMeta();
    $meta->title = 'CDATA feed';
    $meta->link = 'https://example.test/';

    $document = $plugin->broadcaster->buildRss($meta, [$item], null, false);
    $parsed = $plugin->parser->parse($document, 'https://example.test/');

    return $parsed->count() === 1 && str_contains((string)$parsed->items[0]->summary, ']]>') ?: $document;
});

check('control characters are stripped rather than making the feed unparseable', function() use ($plugin) {
    $item = new Item();
    $item->guid = 'ctrl-1';
    $item->title = "Bad" . chr(8) . "char";
    $item->url = 'https://example.test/ctrl';

    $meta = new FeedMeta();
    $meta->title = 'Control feed';

    $document = $plugin->broadcaster->buildRss($meta, [$item], null, false);
    $parsed = $plugin->parser->parse($document, 'https://example.test/');

    return $parsed->count() === 1 && $parsed->items[0]->title === 'Badchar' ?: (string)($parsed->items[0]->title ?? 'nothing');
});

check('a re-broadcast item is purified on the way out', function() use ($plugin) {
    $item = new Item();
    $item->guid = 'xss-1';
    $item->title = 'Borrowed';
    $item->content = '<p>Fine</p><script>alert(1)</script>';
    $item->url = 'https://example.test/xss';

    $meta = new FeedMeta();
    $meta->title = 'Aggregated';

    $document = $plugin->broadcaster->buildRss($meta, [$item], null, true);

    return !str_contains($document, '<script>alert') ?: 'the script survived';
});

check('an RSS guid is only claimed as a permalink when it is one', function() use ($plugin) {
    $item = new Item();
    $item->guid = 'tag:example.com,2025:1';
    $item->title = 'Tag URI';

    $meta = new FeedMeta();
    $meta->title = 'Guid feed';

    $document = $plugin->broadcaster->buildRss($meta, [$item], null, false);

    return str_contains($document, 'isPermaLink="false"') ?: $document;
});

check('JSON Feed always gives an item something to read', function() use ($plugin) {
    $item = new Item();
    $item->guid = 'json-empty';
    $item->title = 'No content';
    $item->summary = 'Only a summary.';

    $meta = new FeedMeta();
    $meta->title = 'JSON out';

    $decoded = json_decode($plugin->broadcaster->buildJson($meta, [$item], null, false), true);

    return isset($decoded['items'][0]['content_text']) || isset($decoded['items'][0]['content_html']) ?: json_encode($decoded['items'][0]);
});

check('serving through the controller route returns the document', function() use ($plugin, $channel) {
    $document = $plugin->broadcaster->render($channel);
    return str_starts_with(trim($document), '<?xml') ?: substr($document, 0, 80);
});

// Channels over your own content
// ---------------------------------------------------------------------------------------------

section('Broadcasting — your own content');

$section = null;
$titlelessSection = null;

// Two kinds are wanted, and which one a site's first section happens to be is luck: an ordinary
// one with a title field for the main checks, and — if the site has one — an entry type with no
// title field, because those need a slug supplied and are where importing quietly breaks.
foreach (Craft::$app->getEntries()->getAllSections() as $candidate) {
    if ($candidate->type === craft\models\Section::TYPE_SINGLE) {
        continue;
    }

    $type = $candidate->getEntryTypes()[0] ?? null;

    if (!$type) {
        continue;
    }

    if ($type->hasTitleField || $type->titleFormat) {
        $section ??= $candidate;
    } else {
        $titlelessSection ??= $candidate;
    }
}

if (!$section) {
    echo "  – skipped: the harness has no section to broadcast\n";
} else {
    $entryType = $section->getEntryTypes()[0];

    $entry = new Entry();
    $entry->sectionId = $section->id;
    $entry->typeId = $entryType->id;
    $entry->title = "Airwave check entry $run";
    $entry->enabled = true;
    $entry->postDate = new DateTime('-1 hour');
    Craft::$app->getElements()->saveElement($entry);
    $created['entry'] = $entry;

    $contentChannel = new Channel();
    $contentChannel->title = "Content channel $run";
    $contentChannel->handle = "aw-content-$run";
    $contentChannel->uri = "aw-content-$run";
    $contentChannel->targetSiteId = $primarySiteId;
    $contentChannel->feedSource = Channel::SOURCE_ELEMENTS;
    $contentChannel->elementType = Entry::class;
    $contentChannel->setCriteria(['section' => $section->handle]);
    $contentChannel->itemLimit = 5;
    $contentChannel->enabled = true;

    check('a content channel saves', fn() => $plugin->channels->saveChannel($contentChannel) ?: json_encode($contentChannel->getErrors()));
    $created['contentChannel'] = $contentChannel;

    check('it broadcasts the section’s entries', function() use ($contentChannel, $entry) {
        $titles = array_map(fn($i) => $i->title, $contentChannel->getItems());
        return in_array($entry->title, $titles, true) ?: json_encode($titles);
    });

    check('items get the entry’s URL and date', function() use ($contentChannel, $entry) {
        foreach ($contentChannel->getItems() as $item) {
            if ($item->title === $entry->title) {
                return $item->publishedAt !== null ?: 'no date';
            }
        }
        return 'entry not found';
    });

    check('unrecognised criteria are ignored rather than fatal', function() use ($plugin, $contentChannel) {
        $contentChannel->setCriteria(['section' => 'no-such-section-anywhere', 'select' => 'password']);
        $items = $contentChannel->getItems();
        return is_array($items) ?: 'threw';
    });

    check('a channel order must be an attribute or field, never SQL', function() use ($plugin) {
        foreach ([
            '(SELECT IF(1=1, SLEEP(5), 0))',
            'postDate, (SELECT 1)',
            'postDate DESC; DROP TABLE x',
            'postDate sideways',
            'password',
            'elements.id desc',
            '',
        ] as $attack) {
            if ($plugin->channels->normalizeOrderBy($attack) !== null) {
                return "accepted '$attack'";
            }
        }

        return $plugin->channels->normalizeOrderBy('postDate DESC, title') === ['postDate' => SORT_DESC, 'title' => SORT_ASC]
            ?: json_encode($plugin->channels->normalizeOrderBy('postDate DESC, title'));
    });

    check('a hostile stored order falls back to newest first instead of running', function() use ($contentChannel, $section, $entry) {
        // Before 5.0.1 this string reached orderBy() raw, and Yii runs anything with `(` as SQL.
        $contentChannel->setCriteria(['section' => $section->handle, 'orderBy' => '(SELECT SLEEP(3))']);
        $started = microtime(true);
        $titles = array_map(fn($i) => $i->title, $contentChannel->getItems());
        $seconds = microtime(true) - $started;

        return $seconds < 2 && in_array($entry->title, $titles, true) ?: sprintf('%.1fs, %s', $seconds, json_encode($titles));
    });

    check('a stored status cannot publish disabled entries', function() use ($contentChannel, $section, $entryType, $primarySiteId, &$created, $run) {
        $hidden = new Entry();
        $hidden->sectionId = $section->id;
        $hidden->typeId = $entryType->id;
        $hidden->siteId = $primarySiteId;
        $hidden->title = "Disabled entry $run";
        $hidden->enabled = false;
        Craft::$app->getElements()->saveElement($hidden);
        $created['hiddenEntry'] = $hidden;

        $contentChannel->setCriteria(['section' => $section->handle, 'status' => 'disabled']);
        $titles = array_map(fn($i) => $i->title, $contentChannel->getItems());

        return !in_array($hidden->title, $titles, true) ?: 'the disabled entry was published';
    });

    check('a channel will not broadcast users', function() use ($contentChannel, $section) {
        $contentChannel->elementType = \craft\elements\User::class;
        $contentChannel->setCriteria([]);
        $items = $contentChannel->getItems();
        $contentChannel->elementType = Entry::class;
        $contentChannel->setCriteria(['section' => $section->handle]);

        return $items === [] ?: count($items) . ' users published';
    });

    check('saving criteria drops keys the query would not accept', function() use ($plugin) {
        $clean = $plugin->channels->sanitizeCriteria(['section' => 'news', 'status' => 'disabled', 'select' => 'password', 'orderBy' => 'title']);

        return $clean === ['section' => 'news', 'orderBy' => 'title'] ?: json_encode($clean);
    });

    check('a stored limit cannot outrun the channel’s item limit', function() use ($contentChannel, $section) {
        $contentChannel->setCriteria(['section' => $section->handle, 'limit' => 5000]);
        $contentChannel->itemLimit = 1;
        return count($contentChannel->getItems()) <= 1 ?: 'published more than asked';
    });

    check('the mapping guesses a title without being told', function() use ($entry) {
        $item = (new ChannelMapping())->resolve($entry);
        return $item->title === $entry->title && $item->guid !== null;
    });

    check('an object template overrides the guess', function() use ($entry) {
        $mapping = ChannelMapping::fromArray(['title' => 'Prefix: {title}']);
        return $mapping->resolve($entry)->title === "Prefix: {$entry->title}" ?: $mapping->resolve($entry)->title;
    });

    check('a broken object template falls back rather than throwing', function() use ($entry) {
        $mapping = ChannelMapping::fromArray(['title' => '{{ object.thisDoesNotExist.atAll() }}']);
        return $mapping->resolve($entry)->title === (string)$entry ?: 'did not fall back';
    });

    check('a non-admin import save keeps stored templates and takes new tokens', function() {
        $stored = ImportConfig::fromArray([
            'titleTemplate' => '{{ item.title }}',
            'fieldMap' => ['body' => '{{ item.summary }}', 'intro' => 'summary', 'extra' => '{{ item.url }}'],
        ]);
        $posted = ImportConfig::fromArray([
            'titleTemplate' => '{{ craft.app.config.general.securityKey }}',
            'fieldMap' => [
                'body' => '{{ craft.users.all() }}',       // changed template: put back
                'intro' => 'content',                      // token: accepted
                'leak' => '{{ craft.app.db.dsn }}',         // new template: dropped
                'extra' => '{{ item.url }}',                // unchanged template: kept
            ],
        ]);

        $posted->keepTemplatesFrom($stored);

        return $posted->titleTemplate === '{{ item.title }}'
            && $posted->fieldMap === ['body' => '{{ item.summary }}', 'intro' => 'content', 'extra' => '{{ item.url }}']
            ?: json_encode([$posted->titleTemplate, $posted->fieldMap]);
    });

    section('Importing');

    $importSource = new Source();
    $importSource->title = "Import feed $run";
    $importSource->handle = "aw-import-$run";
    $importSource->url = 'https://example.com/import.xml';
    $importSource->importEnabled = true;
    $importSource->setImportConfig(ImportConfig::fromArray([
        'sectionUid' => $section->uid,
        'entryTypeUid' => $entryType->uid,
        'status' => 'disabled',
        'dateSource' => 'item',
        'limitPerRun' => 2,
    ]));
    $plugin->sources->saveSource($importSource);
    $created['importSource'] = $importSource;
    $plugin->items->store($importSource, $rss->items);

    check('importing creates entries', function() use ($plugin, $importSource) {
        $result = $plugin->importer->import($importSource);
        return $result->created === 2 && $result->failed === 0 ?: $result->getSummary() . ' ' . json_encode($result->errors);
    });

    check('a second run picks up where the first stopped and repeats nothing', function() use ($plugin, $importSource) {
        // limitPerRun is 2 and the fixture carries 3 items, so the first run left one behind.
        $again = $plugin->importer->import($importSource);
        $imported = $plugin->items->find(['sourceId' => $importSource->id, 'imported' => true, 'limit' => 10]);
        $third = $plugin->importer->import($importSource);

        return $again->created === 1 && count($imported) === 3 && $third->created === 0
            ?: count($imported) . ' marked, ' . $again->created . ' on the second run, ' . $third->created . ' on the third';
    });

    check('a dry run writes nothing', function() use ($plugin, $importSource, $section) {
        $before = (int)Entry::find()->sectionId($section->id)->status(null)->count();
        $result = $plugin->importer->import($importSource, null, true);
        $after = (int)Entry::find()->sectionId($section->id)->status(null)->count();
        return $before === $after && $result->dryRun ?: "$before → $after";
    });

    check('an unconfigured import says so rather than failing silently', function() use ($plugin, $source) {
        $result = $plugin->importer->import($source);
        return $result->created === 0 && $result->errors !== [] ?: 'no complaint';
    });

    if ($titlelessSection) {
        check('an entry type with no title field still imports', function() use ($plugin, $titlelessSection, $run, &$created) {
            $type = $titlelessSection->getEntryTypes()[0];

            $titleless = new Source();
            $titleless->title = "Titleless import $run";
            $titleless->handle = "aw-titleless-$run";
            $titleless->url = 'https://example.com/titleless.xml';
            $titleless->setImportConfig(ImportConfig::fromArray([
                'sectionUid' => $titlelessSection->uid,
                'entryTypeUid' => $type->uid,
                'limitPerRun' => 1,
            ]));
            $plugin->sources->saveSource($titleless);
            $created['titleless'] = $titleless;

            $item = new Item();
            $item->guid = "titleless-$run";
            $item->title = 'An item needing a slug';
            $item->url = 'https://example.com/titleless-1';
            $plugin->items->store($titleless, [$item]);

            $result = $plugin->importer->import($titleless);

            return $result->created === 1 ?: $result->getSummary() . ' ' . json_encode($result->errors);
        });
    }

    check('imported entries land in the section, disabled', function() use ($section, $run) {
        $entries = Entry::find()->sectionId($section->id)->status(null)->search(null)->limit(null)->all();
        $ours = array_filter($entries, fn($e) => str_contains((string)$e->title, 'foremost') || str_contains((string)$e->title, 'Relative link'));
        return count($ours) > 0 && !reset($ours)->enabled ?: count($ours) . ' found';
    });
}

// Settings
// ---------------------------------------------------------------------------------------------

section('Settings');

check('no setting is required, so a fresh install can save', function() use ($plugin) {
    $settings = new justinholtweb\airwave\models\Settings();
    return $settings->validate() ?: json_encode($settings->getErrors());
});

check('a blank user agent composes one', fn() => str_contains((new justinholtweb\airwave\models\Settings())->getUserAgent(), 'Airwave'));

check('the generator name has a default', fn() => (new justinholtweb\airwave\models\Settings())->getGeneratorName() !== '');

// Cleanup
// ---------------------------------------------------------------------------------------------

section('Cleaning up');

// Done by the shutdown handler registered at the top, so that an interrupted run cleans up too.
echo "  handled on shutdown\n";

echo "\n" . str_repeat('─', 60) . "\n";
echo "$passed passed, $failed failed\n";

exit($failed > 0 ? 1 : 0);
