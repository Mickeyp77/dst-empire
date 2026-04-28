<?php
/**
 * src/Empire/LawMonitor/SourcePoller.php
 *
 * Polls free public RSS/HTML sources for law changes relevant to DST Empire
 * structures (federal tax, trust law, state SOS, FinCEN, SCOTUS).
 *
 * Design:
 *  - Each source has a static config: type (rss|html_feed), URL, parser hint.
 *  - pollAll() iterates all sources, fetches with cURL (30s timeout), parses,
 *    deduplicates against last-seen cache file, returns unified item array.
 *  - NO network calls are made at class-load time. Only pollAll() fetches.
 *  - All errors are logged to STDERR and skipped — one bad source never
 *    aborts the run.
 *
 * Item shape returned per source:
 *  {
 *    source:       string   (matches law_changes.source ENUM),
 *    jurisdiction: ?string  (2-char state code or null for federal),
 *    url:          string,
 *    title:        string,
 *    summary:      string,
 *    published_at: string   (Y-m-d H:i:s or best-guess),
 *    raw_content_md: string (title + summary + url, markdown formatted),
 *  }
 *
 * Cache: /tmp/law_monitor_seen_{source}.txt — one URL per line.
 * Deleting the cache forces re-ingestion of all visible items.
 *
 * Namespace: Mnmsos\Empire\LawMonitor
 */

namespace Mnmsos\Empire\LawMonitor;

class SourcePoller
{
    // ─── Source registry ──────────────────────────────────────────────────────
    // type: rss   → parse as RSS/Atom XML
    // type: atom  → parse as Atom XML (same parser, slightly different tags)
    // type: html  → scrape <a> links from a listing page (fallback)
    //
    // NOTE: URLs verified against public endpoints as of 2026-04.
    // Tax Court atom feed verified: https://ustaxcourt.gov/feed.atom
    // FinCEN uses a press-room HTML page; scraped for headline links.
    // SCOTUS slip opinions page scraped for PDF title links.
    // State SOS: TX/DE/WY/NV/SD/CA/NY/FL/IL/OH — HTML scrape of news pages.
    // IRS newsroom RSS: https://www.irs.gov/uac/feed/news (public, no auth)
    // ─────────────────────────────────────────────────────────────────────────

    /** @var array<int,array{id:string,source:string,jurisdiction:?string,type:string,url:string,css_selector:string}> */
    private const SOURCES = [
        // ── Federal ──────────────────────────────────────────────────────────
        [
            'id'           => 'irs_bulletin',
            'source'       => 'irs_bulletin',
            'jurisdiction' => null,
            'type'         => 'rss',
            'url'          => 'https://www.irs.gov/uac/feed/news',
            'css_selector' => '',
        ],
        [
            'id'           => 'tax_court',
            'source'       => 'tax_court',
            'jurisdiction' => null,
            'type'         => 'atom',
            'url'          => 'https://ustaxcourt.gov/feed.atom',
            'css_selector' => '',
        ],
        [
            'id'           => 'fincen',
            'source'       => 'fincen',
            'jurisdiction' => null,
            'type'         => 'html',
            'url'          => 'https://www.fincen.gov/news',
            'css_selector' => 'a[href*="/news/"]',
        ],
        [
            'id'           => 'scotus',
            'source'       => 'scotus',
            'jurisdiction' => null,
            'type'         => 'html',
            'url'          => 'https://www.supremecourt.gov/opinions/slipopinion/25',
            'css_selector' => 'a[href*=".pdf"]',
        ],
        // ── State SOS ─────────────────────────────────────────────────────────
        [
            'id'           => 'sos_tx',
            'source'       => 'state_sos',
            'jurisdiction' => 'TX',
            'type'         => 'html',
            'url'          => 'https://www.sos.state.tx.us/corp/news.shtml',
            'css_selector' => 'a[href]',
        ],
        [
            'id'           => 'sos_de',
            'source'       => 'state_sos',
            'jurisdiction' => 'DE',
            'type'         => 'html',
            'url'          => 'https://corp.delaware.gov/news/',
            'css_selector' => 'h2 a, .entry-title a',
        ],
        [
            'id'           => 'sos_wy',
            'source'       => 'state_sos',
            'jurisdiction' => 'WY',
            'type'         => 'html',
            'url'          => 'https://sos.wyo.gov/news-releases/',
            'css_selector' => 'a[href*="news"]',
        ],
        [
            'id'           => 'sos_nv',
            'source'       => 'state_sos',
            'jurisdiction' => 'NV',
            'type'         => 'html',
            'url'          => 'https://www.nvsos.gov/sos/about/news-media',
            'css_selector' => 'a[href]',
        ],
        [
            'id'           => 'sos_sd',
            'source'       => 'state_sos',
            'jurisdiction' => 'SD',
            'type'         => 'html',
            'url'          => 'https://sdsos.gov/news-events/sos-news.aspx',
            'css_selector' => 'a[href]',
        ],
        [
            'id'           => 'sos_ca',
            'source'       => 'state_sos',
            'jurisdiction' => 'CA',
            'type'         => 'html',
            'url'          => 'https://www.sos.ca.gov/administration/news-releases/',
            'css_selector' => 'a[href*="news"]',
        ],
        [
            'id'           => 'sos_ny',
            'source'       => 'state_sos',
            'jurisdiction' => 'NY',
            'type'         => 'html',
            'url'          => 'https://www.dos.ny.gov/news/',
            'css_selector' => 'a[href*="news"]',
        ],
        [
            'id'           => 'sos_fl',
            'source'       => 'state_sos',
            'jurisdiction' => 'FL',
            'type'         => 'html',
            'url'          => 'https://dos.fl.gov/media/news-releases/',
            'css_selector' => 'a[href*="news"]',
        ],
        [
            'id'           => 'sos_il',
            'source'       => 'state_sos',
            'jurisdiction' => 'IL',
            'type'         => 'html',
            'url'          => 'https://www.ilsos.gov/news/',
            'css_selector' => 'a[href]',
        ],
        [
            'id'           => 'sos_oh',
            'source'       => 'state_sos',
            'jurisdiction' => 'OH',
            'type'         => 'html',
            'url'          => 'https://www.ohiosos.gov/news/',
            'css_selector' => 'a[href]',
        ],
    ];

    private const CACHE_DIR     = '/tmp';
    private const CACHE_PREFIX  = 'law_monitor_seen_';
    private const CURL_TIMEOUT  = 30;
    private const MAX_ITEMS_PER_SOURCE = 20; // Safety cap — don't flood on first run

    // ─────────────────────────────────────────────────────────────────────────
    // PUBLIC API
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Poll all configured sources.
     *
     * Returns array of source result objects:
     * [
     *   {
     *     source_id: string,
     *     source: string,
     *     url: string,
     *     items: [
     *       {
     *         source: string,
     *         jurisdiction: ?string,
     *         url: string,
     *         title: string,
     *         summary: string,
     *         published_at: string,
     *         raw_content_md: string,
     *       }, ...
     *     ],
     *     error: ?string,
     *   }, ...
     * ]
     *
     * @return array<int,array<string,mixed>>
     */
    public static function pollAll(): array
    {
        $results = [];

        foreach (self::SOURCES as $src) {
            $result = [
                'source_id' => $src['id'],
                'source'    => $src['source'],
                'url'       => $src['url'],
                'items'     => [],
                'error'     => null,
            ];

            try {
                $rawHtml = self::fetch($src['url']);

                if ($src['type'] === 'rss') {
                    $items = self::parseRss($rawHtml, $src);
                } elseif ($src['type'] === 'atom') {
                    $items = self::parseAtom($rawHtml, $src);
                } else {
                    $items = self::parseHtml($rawHtml, $src);
                }

                // Deduplicate against cache
                $seenUrls = self::loadSeenCache($src['id']);
                $newItems = [];
                $addedUrls = [];

                foreach ($items as $item) {
                    if (isset($seenUrls[$item['url']])) {
                        continue; // Already processed
                    }
                    $newItems[]            = $item;
                    $addedUrls[]           = $item['url'];

                    if (count($newItems) >= self::MAX_ITEMS_PER_SOURCE) {
                        break;
                    }
                }

                // Persist new URLs to cache
                if (!empty($addedUrls)) {
                    self::updateSeenCache($src['id'], $addedUrls);
                }

                $result['items'] = $newItems;

            } catch (\Throwable $e) {
                $result['error'] = $e->getMessage();
                fwrite(STDERR, '[SourcePoller] ' . $src['id'] . ' failed: ' . $e->getMessage() . "\n");
            }

            $results[] = $result;
        }

        return $results;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE — HTTP
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * cURL fetch. Throws on network error or non-2xx status.
     * Returns raw response body string.
     */
    private static function fetch(string $url): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::CURL_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_HTTPHEADER     => [
                'Accept: text/html,application/xhtml+xml,application/xml,application/rss+xml;q=0.9',
                'User-Agent: VoltOpsLawMonitor/1.0 (+https://voltops.net; compliance@voltops.net)',
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_ENCODING       => 'gzip, deflate',
        ]);

        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);

        if ($err) {
            throw new \RuntimeException("cURL error: {$err}");
        }
        if ($code < 200 || $code >= 300) {
            throw new \RuntimeException("HTTP {$code} for {$url}");
        }
        if ($body === false || $body === '') {
            throw new \RuntimeException("Empty response from {$url}");
        }

        return (string)$body;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE — Parsers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Parse RSS 2.0 XML — IRS newsroom format.
     *
     * @param array<string,mixed> $src
     * @return array<int,array<string,mixed>>
     */
    private static function parseRss(string $xml, array $src): array
    {
        $items = [];

        // Suppress XML parse warnings; libxml errors captured manually
        libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA);
        if ($doc === false) {
            $errs = libxml_get_errors();
            libxml_clear_errors();
            $msg = implode('; ', array_map(fn($e) => trim($e->message), $errs));
            throw new \RuntimeException("RSS parse failed: {$msg}");
        }

        $channel = $doc->channel ?? $doc;
        foreach ($channel->item as $item) {
            $title   = trim((string)($item->title ?? ''));
            $link    = trim((string)($item->link ?? ''));
            $desc    = strip_tags(trim((string)($item->description ?? '')));
            $pubDate = trim((string)($item->pubDate ?? ''));

            if (empty($title) || empty($link)) {
                continue;
            }

            $pubTs = $pubDate ? date('Y-m-d H:i:s', strtotime($pubDate)) : date('Y-m-d H:i:s');

            $items[] = self::buildItem($src, $link, $title, $desc, $pubTs);
        }

        return $items;
    }

    /**
     * Parse Atom XML — Tax Court feed format.
     *
     * @param array<string,mixed> $src
     * @return array<int,array<string,mixed>>
     */
    private static function parseAtom(string $xml, array $src): array
    {
        $items = [];

        libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA);
        if ($doc === false) {
            $errs = libxml_get_errors();
            libxml_clear_errors();
            throw new \RuntimeException('Atom parse failed');
        }

        // Register Atom namespace if needed
        $ns = $doc->getNamespaces(true);
        $atomNs = $ns[''] ?? $ns['atom'] ?? null;

        foreach ($doc->entry as $entry) {
            $title   = trim((string)($entry->title ?? ''));
            $summary = strip_tags(trim((string)($entry->summary ?? $entry->content ?? '')));
            $updated = trim((string)($entry->updated ?? $entry->published ?? ''));

            // Link: Atom uses <link href="..."> or <link rel="alternate">
            $link = '';
            if (isset($entry->link)) {
                foreach ($entry->link as $l) {
                    $attrs = $l->attributes();
                    $rel   = (string)($attrs['rel'] ?? 'alternate');
                    $href  = (string)($attrs['href'] ?? '');
                    if ($rel === 'alternate' && $href) {
                        $link = $href;
                        break;
                    }
                    if ($href) {
                        $link = $href;
                    }
                }
            }

            if (empty($title)) {
                continue;
            }
            if (empty($link)) {
                // Fallback: use <id> as URL if it looks like a URL
                $id = trim((string)($entry->id ?? ''));
                if (str_starts_with($id, 'http')) {
                    $link = $id;
                }
            }
            if (empty($link)) {
                continue;
            }

            $pubTs = $updated ? date('Y-m-d H:i:s', strtotime($updated)) : date('Y-m-d H:i:s');
            $items[] = self::buildItem($src, $link, $title, $summary, $pubTs);
        }

        return $items;
    }

    /**
     * Scrape HTML page for headline links (FinCEN, SCOTUS, State SOS).
     * Uses a simple regex-based link extractor — no DOM extension required.
     *
     * For each <a href> found:
     *  - href becomes the item URL (resolved to absolute)
     *  - anchor text becomes the title
     *  - No summary available; raw_content_md will just be title + url
     *
     * @param array<string,mixed> $src
     * @return array<int,array<string,mixed>>
     */
    private static function parseHtml(string $html, array $src): array
    {
        $items   = [];
        $baseUrl = $src['url'];

        // Extract <a href="...">text</a> pairs
        // Matches both single and double quoted hrefs
        $pattern = '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is';
        preg_match_all($pattern, $html, $matches, PREG_SET_ORDER);

        $seen = [];

        foreach ($matches as $m) {
            $href = trim($m[1]);
            $text = trim(strip_tags($m[2]));

            // Skip empty text, anchors, mailto, javascript
            if (empty($text) || strlen($text) < 5) {
                continue;
            }
            if (str_starts_with($href, '#') || str_starts_with($href, 'mailto:') || str_starts_with($href, 'javascript:')) {
                continue;
            }

            // Resolve relative URLs
            $absUrl = self::resolveUrl($href, $baseUrl);

            // Deduplicate within this parse run
            if (isset($seen[$absUrl])) {
                continue;
            }
            $seen[$absUrl] = true;

            // Apply source-specific filtering
            if (!self::htmlLinkPassesFilter($absUrl, $text, $src)) {
                continue;
            }

            $items[] = self::buildItem($src, $absUrl, $text, '', date('Y-m-d H:i:s'));
        }

        return $items;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE — Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Normalise one item into the canonical shape.
     *
     * @param array<string,mixed> $src
     * @return array<string,mixed>
     */
    private static function buildItem(array $src, string $url, string $title, string $summary, string $publishedAt): array
    {
        $summary = mb_strimwidth($summary, 0, 500, '...');

        $rawMd = "## {$title}\n\n";
        if ($summary) {
            $rawMd .= "{$summary}\n\n";
        }
        $rawMd .= "Source: <{$url}>";

        return [
            'source'         => $src['source'],
            'jurisdiction'   => $src['jurisdiction'],
            'url'            => $url,
            'title'          => $title,
            'summary'        => $summary,
            'published_at'   => $publishedAt,
            'raw_content_md' => $rawMd,
        ];
    }

    /**
     * Source-specific noise filter for HTML scrapers.
     * Returns false if the link should be discarded.
     *
     * @param array<string,mixed> $src
     */
    private static function htmlLinkPassesFilter(string $url, string $text, array $src): bool
    {
        $id = $src['id'];

        // FinCEN: only news/advisory/notice URLs
        if ($id === 'fincen') {
            return (bool) preg_match('#/(news|advisory|notice|alert|guidance)#i', $url);
        }

        // SCOTUS: only PDF slip opinion links that look like case names
        if ($id === 'scotus') {
            return str_ends_with(strtolower($url), '.pdf')
                && strlen($text) > 8;
        }

        // State SOS: reject nav links (About, Contact, Home, Login, etc.)
        if (str_starts_with($id, 'sos_')) {
            $navWords = ['about', 'contact', 'home', 'login', 'search', 'site map',
                         'privacy', 'accessibility', 'facebook', 'twitter', 'linkedin'];
            $textLower = strtolower($text);
            foreach ($navWords as $w) {
                if (str_contains($textLower, $w)) {
                    return false;
                }
            }
            // Must look like a news/press-release URL or have meaningful text
            return strlen($text) >= 10;
        }

        return true;
    }

    /**
     * Resolve a potentially-relative URL against a base URL.
     */
    private static function resolveUrl(string $href, string $base): string
    {
        if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
            return $href;
        }

        $parsed = parse_url($base);
        $scheme = ($parsed['scheme'] ?? 'https');
        $host   = ($parsed['host'] ?? '');

        if (str_starts_with($href, '//')) {
            return $scheme . ':' . $href;
        }

        if (str_starts_with($href, '/')) {
            return $scheme . '://' . $host . $href;
        }

        // Relative path — resolve against base directory
        $basePath = dirname($parsed['path'] ?? '/');
        return $scheme . '://' . $host . rtrim($basePath, '/') . '/' . ltrim($href, '/');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE — Seen-URL cache (flat file in /tmp)
    // ─────────────────────────────────────────────────────────────────────────

    /** @return array<string,bool> */
    private static function loadSeenCache(string $sourceId): array
    {
        $path = self::CACHE_DIR . '/' . self::CACHE_PREFIX . $sourceId . '.txt';
        if (!file_exists($path)) {
            return [];
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $map   = [];
        foreach ($lines as $line) {
            $map[trim($line)] = true;
        }
        return $map;
    }

    /** @param string[] $newUrls */
    private static function updateSeenCache(string $sourceId, array $newUrls): void
    {
        $path = self::CACHE_DIR . '/' . self::CACHE_PREFIX . $sourceId . '.txt';
        $fp   = @fopen($path, 'a');
        if ($fp) {
            foreach ($newUrls as $u) {
                fwrite($fp, trim($u) . "\n");
            }
            fclose($fp);
        }
    }
}
