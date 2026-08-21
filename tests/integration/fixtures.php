<?php
/**
 * Feed documents to parse.
 *
 * Hand-written rather than downloaded, and deliberately awkward: every one of these carries at
 * least one thing that has broken a real feed reader — a namespaced element, an author with an
 * email in it, a relative link, a CDATA block, an image only present in the body.
 */

return [
    'rss' => <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0"
     xmlns:content="http://purl.org/rss/1.0/modules/content/"
     xmlns:dc="http://purl.org/dc/elements/1.1/"
     xmlns:media="http://search.yahoo.com/mrss/">
  <channel>
    <title>Airwave Test Feed</title>
    <link>https://example.com/blog</link>
    <description>A feed for testing</description>
    <language>en-gb</language>
    <ttl>60</ttl>
    <image><url>https://example.com/logo.png</url><title>Logo</title><link>https://example.com</link></image>
    <item>
      <title>First &amp; foremost</title>
      <link>https://example.com/blog/first</link>
      <guid isPermaLink="true">https://example.com/blog/first</guid>
      <pubDate>Mon, 03 Feb 2025 09:00:00 +0000</pubDate>
      <description>A short summary about cycling.</description>
      <content:encoded><![CDATA[<p>The whole article about <strong>cycling</strong>.</p><script>alert(1)</script>]]></content:encoded>
      <author>editor@example.com (Jane Editor)</author>
      <category>Sport</category>
      <category>Cycling</category>
      <media:thumbnail url="https://example.com/img/first.jpg"/>
      <enclosure url="https://example.com/audio/first.mp3" type="audio/mpeg" length="12345"/>
    </item>
    <item>
      <title>Relative link</title>
      <link>/blog/second</link>
      <pubDate>Tue, 04 Feb 2025 09:00:00 +0000</pubDate>
      <description><![CDATA[<p>Body with a picture <img src="/img/second.png" width="600"> in it.</p>]]></description>
      <dc:creator>Sam Writer</dc:creator>
    </item>
    <item>
      <title>No date, no guid, tracking pixel</title>
      <link>https://example.com/blog/third</link>
      <description><![CDATA[<p>Nothing much.<img src="https://tracker.example/px.gif" width="1" height="1"></p>]]></description>
    </item>
  </channel>
</rss>
XML,

    'atom' => <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<feed xmlns="http://www.w3.org/2005/Atom" xml:lang="fr">
  <title>Atom Test</title>
  <subtitle>Le flux</subtitle>
  <id>https://atom.example.com/</id>
  <updated>2025-03-01T12:00:00Z</updated>
  <link rel="self" href="https://atom.example.com/feed"/>
  <link rel="alternate" href="https://atom.example.com/"/>
  <logo>https://atom.example.com/logo.png</logo>
  <entry>
    <title>XHTML content</title>
    <id>tag:atom.example.com,2025:1</id>
    <updated>2025-03-02T12:00:00Z</updated>
    <published>2025-03-01T08:00:00Z</published>
    <link rel="alternate" href="https://atom.example.com/one"/>
    <link rel="enclosure" href="https://atom.example.com/one.pdf" type="application/pdf" length="999"/>
    <summary>A summary.</summary>
    <content type="xhtml">
      <div xmlns="http://www.w3.org/1999/xhtml"><p>Real <em>markup</em>.</p></div>
    </content>
    <author><name>Ada Lovelace</name></author>
    <category term="computing"/>
  </entry>
  <entry>
    <title>Updated only</title>
    <id>tag:atom.example.com,2025:2</id>
    <updated>2025-03-05T12:00:00Z</updated>
    <link href="https://atom.example.com/two"/>
    <content type="html">&lt;p&gt;Escaped HTML.&lt;/p&gt;</content>
  </entry>
</feed>
XML,

    'rdf' => <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
         xmlns="http://purl.org/rss/1.0/"
         xmlns:dc="http://purl.org/dc/elements/1.1/">
  <channel rdf:about="https://rdf.example.com/">
    <title>RDF Test</title>
    <link>https://rdf.example.com/</link>
    <description>Old school</description>
  </channel>
  <item rdf:about="https://rdf.example.com/story-1">
    <title>An RDF story</title>
    <link>https://rdf.example.com/story-1</link>
    <description>Summary text.</description>
    <dc:date>2025-04-01T10:00:00Z</dc:date>
    <dc:creator>Old Timer</dc:creator>
  </item>
</rdf:RDF>
XML,

    'json' => <<<'JSON'
{
  "version": "https://jsonfeed.org/version/1.1",
  "title": "JSON Test",
  "home_page_url": "https://json.example.com/",
  "description": "A JSON feed",
  "items": [
    {
      "id": "json-1",
      "url": "https://json.example.com/1",
      "title": "JSON item",
      "summary": "The summary.",
      "content_html": "<p>The content.</p>",
      "date_published": "2025-05-01T09:00:00Z",
      "authors": [{ "name": "Jay Sonn" }],
      "tags": ["one", "two"],
      "image": "https://json.example.com/1.png",
      "attachments": [{ "url": "https://json.example.com/1.mp3", "mime_type": "audio/mpeg", "size_in_bytes": 42 }]
    },
    {
      "id": "json-2",
      "url": "https://json.example.com/2",
      "title": "Old style author",
      "content_text": "Plain text only.",
      "author": { "name": "Solo Author" },
      "date_published": "2025-05-02T09:00:00Z"
    }
  ]
}
JSON,

    'doctype' => '<?xml version="1.0"?><!DOCTYPE feed [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><rss version="2.0"><channel><title>&xxe;</title></channel></rss>',

    'notafeed' => '<?xml version="1.0"?><catalogue><book><title>Nope</title></book></catalogue>',

    'html' => <<<'HTML'
<!doctype html>
<html><head>
<title>A blog</title>
<link rel="alternate" type="application/rss+xml" title="Main feed" href="/feed.xml">
<link rel="alternate" type="application/feed+json" href="https://blog.example.com/feed.json">
<link rel="stylesheet" href="/style.css">
<link rel="alternate" type="text/html" hreflang="fr" href="/fr/">
</head><body>Hello</body></html>
HTML,
];
