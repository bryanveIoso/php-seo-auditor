<?php
declare(strict_types=1);

/**
 * PHP SEO Auditor - Self-Hosted Website Crawler & Report Generator
 * Inspired by claude-seo (https://github.com/AgricIDaniel/claude-seo)
 * 
 * Features converted/adapted for pure PHP hosting:
 * - URL form input + crawl (cURL + DOM parsing)
 * - Technical SEO checks (title, meta, headings, canonical, viewport, robots hints)
 * - On-page content analysis (word count, heading structure)
 * - Media & Links analysis (alt texts, internal/external ratio)
 * - Basic Structured Data (JSON-LD detection)
 * - Rule-based scoring + prioritized recommendations (mimics sub-skills/agents)
 * - Beautiful, professional HTML report with Tailwind
 * - robots.txt + sitemap summary option
 * - Print-friendly + export ready
 * 
 * Limitations vs original (honest):
 * - No Playwright headless rendering (static HTML only; SPA may show limited content)
 * - No AI/LLM agents or parallel sub-skills (deterministic PHP rules)
 * - No Google APIs, DataForSEO, full E-E-A-T, backlinks, or PDF/Excel out of box
 * - Single page focus by default (extendable)
 * 
 * Production notes for Plesk/PHP server:
 * - Requires: PHP 8.1+, curl, dom, mbstring, openssl extensions (usually enabled)
 * - Place in a folder, access via https://yourdomain.com/seo-auditor/
 * - For production: disable error display, add rate limiting, HTTPS enforce
 * - To add real PDF: composer require mpdf/mpdf then extend render_pdf()
 * - To improve crawling (SPA): add Node + Puppeteer or call external Firecrawl if available
 */

error_reporting(E_ALL);
ini_set('display_errors', '1'); // Change to 0 in production
ini_set('default_charset', 'UTF-8');

session_start();

// ========== CONFIG ==========
const APP_NAME = 'PHP SEO Auditor';
const VERSION = '1.0.0';
const TIMEOUT_SECONDS = 12;
const MAX_REDIRECTS = 5;
const USER_AGENT = 'Mozilla/5.0 (compatible; PHP-SEO-Auditor/1.0; Self-hosted SEO tool)';

// ========== SECURITY & HELPERS ==========
function sanitize_url(string $url): string {
    $url = trim($url);
    if (!preg_match('#^https?://#i', $url)) {
        $url = 'https://' . $url;
    }
    return filter_var($url, FILTER_SANITIZE_URL) ?: '';
}

function is_valid_url(string $url): bool {
    if (!filter_var($url, FILTER_VALIDATE_URL)) return false;
    $scheme = parse_url($url, PHP_URL_SCHEME);
    return in_array(strtolower($scheme ?? ''), ['http', 'https'], true);
}

function get_host(string $url): string {
    return parse_url($url, PHP_URL_HOST) ?: '';
}

function resolve_url(string $base, string $relative): string {
    if (parse_url($relative, PHP_URL_SCHEME)) return $relative;
    if (str_starts_with($relative, '//')) return 'https:' . $relative;
    if (str_starts_with($relative, '/')) {
        return rtrim(parse_url($base, PHP_URL_SCHEME) . '://' . get_host($base), '/') . $relative;
    }
    return rtrim($base, '/') . '/' . ltrim($relative, '/');
}

// ========== CRAWLER ==========
function fetch_page(string $url): array {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => MAX_REDIRECTS,
        CURLOPT_TIMEOUT => TIMEOUT_SECONDS,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_USERAGENT => USER_AGENT,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_ENCODING => 'gzip, deflate',
        CURLOPT_HTTPHEADER => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.5',
        ],
    ]);

    $html = curl_exec($ch);
    $info = curl_getinfo($ch);
    $error = curl_error($ch);
    $errno = curl_errno($ch);
    curl_close($ch);

    return [
        'html' => $html ?: '',
        'info' => $info,
        'error' => $error,
        'errno' => $errno,
        'success' => ($errno === 0 && $info['http_code'] >= 200 && $info['http_code'] < 400 && !empty($html)),
    ];
}

function fetch_robots_and_sitemap(string $base_url): array {
    $host = get_host($base_url);
    $scheme = parse_url($base_url, PHP_URL_SCHEME) ?: 'https';
    $robots_url = "$scheme://$host/robots.txt";
    $sitemap_url = "$scheme://$host/sitemap.xml";

    $robots = fetch_page($robots_url);
    $sitemap = fetch_page($sitemap_url);

    $robots_content = $robots['success'] ? substr($robots['html'], 0, 2000) : null;
    $sitemap_locs = [];

    if ($sitemap['success']) {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        if (@$dom->loadXML($sitemap['html'])) {
            foreach ($dom->getElementsByTagName('loc') as $loc) {
                $sitemap_locs[] = trim($loc->textContent);
                if (count($sitemap_locs) >= 10) break; // limit
            }
        }
    }

    return [
        'robots_url' => $robots_url,
        'robots_content' => $robots_content,
        'sitemap_url' => $sitemap_url,
        'sitemap_sample' => $sitemap_locs,
        'has_robots' => $robots['success'],
        'has_sitemap' => $sitemap['success'],
    ];
}

// ========== PARSER ==========
function parse_seo_data(string $html, string $final_url): array {
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $html = '<?xml encoding="utf-8" ?>' . $html;
    @$dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

    $data = [
        'final_url' => $final_url,
        'host' => get_host($final_url),
        'title' => '',
        'meta_description' => '',
        'meta_robots' => '',
        'canonical' => '',
        'viewport' => '',
        'headings' => ['h1' => [], 'h2' => [], 'h3' => [], 'h4' => [], 'h5' => [], 'h6' => []],
        'links' => ['total' => 0, 'internal' => 0, 'external' => 0, 'samples_internal' => [], 'samples_external' => []],
        'images' => ['total' => 0, 'with_alt' => 0, 'without_alt' => 0],
        'schemas' => [],
        'word_count' => 0,
        'lang' => '',
        'charset' => '',
    ];

    // Title
    $titleEl = $dom->getElementsByTagName('title')->item(0);
    if ($titleEl) $data['title'] = trim($titleEl->textContent);

    // Meta tags
    foreach ($dom->getElementsByTagName('meta') as $meta) {
        $name = strtolower($meta->getAttribute('name') ?: $meta->getAttribute('property') ?: '');
        $content = trim($meta->getAttribute('content') ?: '');
        if ($name === 'description') $data['meta_description'] = $content;
        if ($name === 'robots') $data['meta_robots'] = $content;
        if ($name === 'viewport') $data['viewport'] = $content;
    }

    // Canonical
    foreach ($dom->getElementsByTagName('link') as $link) {
        if (strtolower($link->getAttribute('rel')) === 'canonical') {
            $data['canonical'] = trim($link->getAttribute('href'));
            break;
        }
    }

    // Headings
    for ($i = 1; $i <= 6; $i++) {
        $tag = "h$i";
        foreach ($dom->getElementsByTagName($tag) as $h) {
            $text = trim(preg_replace('/\s+/', ' ', $h->textContent));
            if ($text) $data['headings'][$tag][] = $text;
        }
    }

    // Links
    $baseHost = $data['host'];
    foreach ($dom->getElementsByTagName('a') as $a) {
        $href = trim($a->getAttribute('href'));
        if (!$href || str_starts_with($href, '#') || str_starts_with($href, 'javascript:') || str_starts_with($href, 'mailto:')) continue;
        $data['links']['total']++;
        $absolute = resolve_url($final_url, $href);
        $linkHost = get_host($absolute);
        if ($linkHost === $baseHost || $linkHost === '') {
            $data['links']['internal']++;
            if (count($data['links']['samples_internal']) < 5) $data['links']['samples_internal'][] = $href;
        } else {
            $data['links']['external']++;
            if (count($data['links']['samples_external']) < 5) $data['links']['samples_external'][] = $href;
        }
    }

    // Images
    foreach ($dom->getElementsByTagName('img') as $img) {
        $data['images']['total']++;
        if (trim($img->getAttribute('alt'))) {
            $data['images']['with_alt']++;
        } else {
            $data['images']['without_alt']++;
        }
    }

    // JSON-LD Schema
    foreach ($dom->getElementsByTagName('script') as $script) {
        if (strtolower($script->getAttribute('type')) === 'application/ld+json') {
            $json = trim($script->textContent);
            $decoded = json_decode($json, true);
            if (json_last_error() === JSON_ERROR_NONE && $decoded) {
                $data['schemas'][] = $decoded;
            }
        }
    }

    // Language & charset
    $htmlEl = $dom->getElementsByTagName('html')->item(0);
    if ($htmlEl) $data['lang'] = strtolower($htmlEl->getAttribute('lang') ?: '');
    foreach ($dom->getElementsByTagName('meta') as $meta) {
        if (strtolower($meta->getAttribute('http-equiv')) === 'content-type' || strtolower($meta->getAttribute('charset'))) {
            $data['charset'] = $meta->getAttribute('charset') ?: 'utf-8';
            break;
        }
    }

    // Rough word count (visible text)
    $bodyText = '';
    $body = $dom->getElementsByTagName('body')->item(0);
    if ($body) $bodyText = $body->textContent;
    $data['word_count'] = str_word_count(strip_tags($bodyText));

    return $data;
}

// ========== ANALYSIS & SCORING (Rule-based "agents") ==========
function analyze_seo(array $data, array $extra = []): array {
    $issues = [];
    $score = 100;
    $category_scores = ['technical' => 100, 'content' => 100, 'media_links' => 100, 'structured_data' => 100];

    $title_len = mb_strlen($data['title']);
    if (empty($data['title'])) {
        $issues[] = ['level' => 'critical', 'category' => 'technical', 'title' => 'Missing Title Tag', 'recommendation' => 'Add a unique, descriptive <title> tag (ideally 50-60 characters).', 'impact' => 'Critical for search rankings, SERP display, and click-through rate.'];
        $score -= 20; $category_scores['technical'] -= 25;
    } elseif ($title_len < 30) {
        $issues[] = ['level' => 'high', 'category' => 'technical', 'title' => 'Title Too Short', 'recommendation' => 'Expand title to 50-60 characters with primary keyword.', 'impact' => 'May not fully communicate page topic to search engines and users.'];
        $score -= 8; $category_scores['technical'] -= 10;
    } elseif ($title_len > 70) {
        $issues[] = ['level' => 'medium', 'category' => 'technical', 'title' => 'Title Too Long', 'recommendation' => 'Shorten title; Google typically shows ~60 chars.', 'impact' => 'Truncated in SERPs, reduced visibility.'];
        $score -= 5; $category_scores['technical'] -= 8;
    }

    $desc_len = mb_strlen($data['meta_description']);
    if (empty($data['meta_description'])) {
        $issues[] = ['level' => 'high', 'category' => 'technical', 'title' => 'Missing Meta Description', 'recommendation' => 'Add a compelling meta description (120-160 characters) with call-to-action.', 'impact' => 'Lost opportunity for SERP snippet control and CTR.'];
        $score -= 12; $category_scores['technical'] -= 15;
    } elseif ($desc_len < 80 || $desc_len > 180) {
        $issues[] = ['level' => 'medium', 'category' => 'technical', 'title' => 'Suboptimal Meta Description Length', 'recommendation' => 'Aim for 120-160 characters.', 'impact' => 'May be truncated or less compelling.'];
        $score -= 4; $category_scores['technical'] -= 6;
    }

    if (empty($data['viewport'])) {
        $issues[] = ['level' => 'high', 'category' => 'technical', 'title' => 'Missing Viewport Meta Tag', 'recommendation' => 'Add <meta name="viewport" content="width=device-width, initial-scale=1"> for mobile-friendliness.', 'impact' => 'Poor mobile experience; Google uses mobile-first indexing.'];
        $score -= 10; $category_scores['technical'] -= 12;
    }

    if (empty($data['canonical'])) {
        $issues[] = ['level' => 'medium', 'category' => 'technical', 'title' => 'No Canonical Tag Detected', 'recommendation' => 'Add canonical link to prevent duplicate content issues (especially important for dynamic/PHP sites).', 'impact' => 'Potential duplicate content penalties or diluted ranking signals.'];
        $score -= 6; $category_scores['technical'] -= 8;
    }

    // Headings
    $h1_count = count($data['headings']['h1']);
    if ($h1_count === 0) {
        $issues[] = ['level' => 'high', 'category' => 'content', 'title' => 'No H1 Heading Found', 'recommendation' => 'Add exactly one primary H1 that includes your main keyword.', 'impact' => 'Missed semantic signal for page topic and accessibility.'];
        $score -= 10; $category_scores['content'] -= 15;
    } elseif ($h1_count > 1) {
        $issues[] = ['level' => 'medium', 'category' => 'content', 'title' => 'Multiple H1 Tags', 'recommendation' => 'Use only one H1 per page. Convert extras to H2/H3.', 'impact' => 'Dilutes topical focus and confuses screen readers.'];
        $score -= 5; $category_scores['content'] -= 8;
    }

    if (count($data['headings']['h2']) === 0 && $h1_count > 0) {
        $issues[] = ['level' => 'low', 'category' => 'content', 'title' => 'No H2 Subheadings', 'recommendation' => 'Break content into logical sections with H2 headings for better readability and SEO.', 'impact' => 'Long walls of text hurt user experience and scannability.'];
        $score -= 3; $category_scores['content'] -= 5;
    }

    // Content length
    if ($data['word_count'] < 300) {
        $issues[] = ['level' => 'high', 'category' => 'content', 'title' => 'Thin Content', 'recommendation' => 'Expand to at least 800-1500+ words with valuable, original information (E-E-A-T aligned).', 'impact' => 'May be seen as low-value; harder to rank for competitive terms.'];
        $score -= 12; $category_scores['content'] -= 18;
    } elseif ($data['word_count'] < 600) {
        $issues[] = ['level' => 'medium', 'category' => 'content', 'title' => 'Moderate Content Length', 'recommendation' => 'Consider expanding for deeper coverage and better rankings.', 'impact' => 'Competitive pages often have more comprehensive content.'];
        $score -= 5; $category_scores['content'] -= 7;
    }

    // Images
    $img_total = $data['images']['total'];
    if ($img_total > 0) {
        $alt_pct = ($data['images']['with_alt'] / $img_total) * 100;
        if ($alt_pct < 70) {
            $issues[] = ['level' => 'medium', 'category' => 'media_links', 'title' => 'Low Image Alt Text Coverage', 'recommendation' => sprintf('Add descriptive alt text to %.0f%% of images (currently %.0f%%).', 100 - $alt_pct, $alt_pct), 'impact' => 'Accessibility issues + missed image search traffic and context for crawlers.'];
            $score -= 6; $category_scores['media_links'] -= 10;
        }
    }

    // Links
    if ($data['links']['total'] > 0) {
        $internal_ratio = $data['links']['internal'] / $data['links']['total'];
        if ($internal_ratio < 0.3) {
            $issues[] = ['level' => 'low', 'category' => 'media_links', 'title' => 'Low Internal Linking', 'recommendation' => 'Add more contextual internal links to important pages on your site.', 'impact' => 'Weaker site architecture and crawlability.'];
            $score -= 3; $category_scores['media_links'] -= 5;
        }
    }

    // Schema
    if (empty($data['schemas'])) {
        $issues[] = ['level' => 'medium', 'category' => 'structured_data', 'title' => 'No Structured Data (JSON-LD) Detected', 'recommendation' => 'Add JSON-LD for Organization, WebSite, Article, or appropriate schema type. Use Google\'s Rich Results Test.', 'impact' => 'Missed rich snippets (stars, FAQ, etc.) and better entity understanding by Google.'];
        $score -= 7; $category_scores['structured_data'] -= 20;
    } else {
        $has_org = false;
        foreach ($data['schemas'] as $s) {
            $types = isset($s['@type']) ? (array)$s['@type'] : [];
            if (in_array('Organization', $types, true) || in_array('WebSite', $types, true)) $has_org = true;
        }
        if (!$has_org) {
            $issues[] = ['level' => 'low', 'category' => 'structured_data', 'title' => 'Schema Present but No Organization/WebSite', 'recommendation' => 'Add Organization or WebSite schema for better brand/entity signals.', 'impact' => 'Weaker E-E-A-T and knowledge graph connection.'];
            $score -= 3; $category_scores['structured_data'] -= 8;
        }
    }

    // HTTPS check (from final URL)
    if (!str_starts_with($data['final_url'], 'https://')) {
        $issues[] = ['level' => 'critical', 'category' => 'technical', 'title' => 'Site Not Using HTTPS', 'recommendation' => 'Migrate to HTTPS immediately (free Let\'s Encrypt via Plesk).', 'impact' => 'Security warning in browsers + ranking disadvantage.'];
        $score -= 15; $category_scores['technical'] -= 20;
    }

    // Final normalization
    $score = max(0, min(100, (int)$score));
    foreach ($category_scores as $k => $v) {
        $category_scores[$k] = max(0, min(100, (int)$v));
    }

    // Sort issues by severity
    usort($issues, function($a, $b) {
        $order = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
        return $order[$a['level']] <=> $order[$b['level']];
    });

    return [
        'overall_score' => $score,
        'category_scores' => $category_scores,
        'issues' => $issues,
        'issue_count' => count($issues),
        'has_critical' => count(array_filter($issues, fn($i) => $i['level'] === 'critical')) > 0,
    ];
}

// ========== REPORT RENDERING ==========
function render_report(array $data, array $analysis, array $extra = []): string {
    $score = $analysis['overall_score'];
    $color = $score >= 85 ? 'emerald' : ($score >= 70 ? 'amber' : ($score >= 50 ? 'orange' : 'rose'));

    ob_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($data['title'] ?: 'SEO Audit Report') ?> | <?= APP_NAME ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&amp;family=Space+Grotesk:wght@500;600&amp;display=swap');
        :root { --font-sans: 'Inter', system_ui, sans-serif; }
        body { font-family: var(--font-sans); }
        .score-circle {
            width: 140px; height: 140px;
            background: conic-gradient(#10b981 calc(<?= $score ?> * 1%), #e5e7eb 0);
            border-radius: 9999px;
            display: flex; align-items: center; justify-content: center;
        }
        .section-header { font-family: 'Space Grotesk', sans-serif; letter-spacing: -.025em; }
        .finding-row:hover { background-color: #f8fafc; }
        .metric-value { font-variant-numeric: tabular-nums; }
        @media print {
            .no-print { display: none !important; }
            body { background: white; }
        }
    </style>
</head>
<body class="bg-slate-50 text-slate-900">
    <div class="max-w-7xl mx-auto">
        <!-- Header -->
        <div class="bg-white border-b sticky top-0 z-50 no-print">
            <div class="px-8 py-5 flex items-center justify-between">
                <div class="flex items-center gap-x-3">
                    <div class="w-10 h-10 bg-emerald-600 rounded-2xl flex items-center justify-center text-white">
                        <i class="fa-solid fa-chart-line text-2xl"></i>
                    </div>
                    <div>
                        <div class="font-semibold text-2xl tracking-tighter"><?= APP_NAME ?></div>
                        <div class="text-xs text-slate-500 -mt-1">Self-hosted • Inspired by Claude SEO</div>
                    </div>
                </div>
                <div class="flex items-center gap-x-4 text-sm">
                    <div class="px-3 py-1.5 bg-slate-100 rounded-2xl text-slate-600 flex items-center gap-x-2">
                        <i class="fa-solid fa-globe fa-fw"></i>
                        <span class="font-medium"><?= htmlspecialchars($data['host']) ?></span>
                    </div>
                    <button onclick="window.print()" 
                            class="flex items-center gap-x-2 px-4 py-2 bg-white border hover:bg-slate-50 transition-colors rounded-2xl text-sm font-medium">
                        <i class="fa-solid fa-print fa-fw"></i>
                        <span>Print / PDF</span>
                    </button>
                    <a href="?" class="flex items-center gap-x-2 px-4 py-2 bg-emerald-600 hover:bg-emerald-700 transition-colors text-white rounded-2xl text-sm font-medium">
                        <i class="fa-solid fa-redo fa-fw"></i>
                        <span>New Audit</span>
                    </a>
                </div>
            </div>
        </div>

        <div class="px-8 pt-8 pb-12">
            <!-- Hero / Score -->
            <div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-6 mb-8">
                <div>
                    <div class="uppercase tracking-[3px] text-xs font-semibold text-emerald-600 mb-1">SEO AUDIT REPORT</div>
                    <h1 class="text-4xl lg:text-5xl font-semibold tracking-tighter section-header"><?= htmlspecialchars($data['title'] ?: 'Untitled Page') ?></h1>
                    <div class="mt-2 flex items-center gap-x-3 text-sm text-slate-600">
                        <div><i class="fa-solid fa-link fa-fw mr-1.5"></i><?= htmlspecialchars($data['final_url']) ?></div>
                        <div class="text-slate-300">•</div>
                        <div><?= date('F j, Y \a\t H:i') ?></div>
                    </div>
                </div>

                <!-- Score -->
                <div class="flex items-center gap-x-6">
                    <div class="text-center">
                        <div class="score-circle mx-auto mb-2 shadow-inner" style="background: conic-gradient(#10b981 calc(<?= $score ?> * 1%), #e5e7eb 0);">
                            <div class="w-[108px] h-[108px] bg-white rounded-full flex flex-col items-center justify-center shadow-sm">
                                <div class="text-5xl font-semibold tracking-tighter text-<?= $color ?>-600"><?= $score ?></div>
                                <div class="text-[10px] font-medium text-slate-500 -mt-1">/ 100</div>
                            </div>
                        </div>
                        <div class="text-xs uppercase tracking-widest font-semibold text-<?= $color ?>-600">OVERALL SCORE</div>
                    </div>
                    
                    <div class="hidden lg:block w-px h-20 bg-slate-200"></div>
                    
                    <div class="grid grid-cols-2 gap-x-8 text-sm">
                        <div>
                            <div class="text-slate-500 text-xs">STATUS</div>
                            <div class="font-semibold flex items-center gap-x-1.5 mt-0.5">
                                <?php if ($analysis['has_critical']): ?>
                                    <span class="text-rose-600"><i class="fa-solid fa-exclamation-triangle"></i> Needs Work</span>
                                <?php elseif ($score >= 80): ?>
                                    <span class="text-emerald-600"><i class="fa-solid fa-check-circle"></i> Strong</span>
                                <?php else: ?>
                                    <span class="text-amber-600"><i class="fa-solid fa-exclamation-circle"></i> Good Start</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div>
                            <div class="text-slate-500 text-xs">ISSUES FOUND</div>
                            <div class="font-semibold mt-0.5 text-2xl tracking-tighter"><?= $analysis['issue_count'] ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Stats -->
            <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4 mb-8">
                <?php
                $stats = [
                    ['label' => 'Load Time', 'value' => isset($extra['load_time']) ? number_format($extra['load_time'], 2).'s' : 'N/A', 'icon' => 'clock'],
                    ['label' => 'Word Count', 'value' => number_format($data['word_count']), 'icon' => 'file-lines'],
                    ['label' => 'H1 Tags', 'value' => count($data['headings']['h1']), 'icon' => 'heading'],
                    ['label' => 'Images w/ Alt', 'value' => $data['images']['total'] ? round(($data['images']['with_alt']/$data['images']['total'])*100).'%' : '—', 'icon' => 'image'],
                    ['label' => 'Internal Links', 'value' => $data['links']['internal'], 'icon' => 'link'],
                    ['label' => 'Schema Blocks', 'value' => count($data['schemas']), 'icon' => 'code'],
                ];
                foreach ($stats as $stat):
                ?>
                <div class="bg-white border rounded-3xl p-5">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-xs uppercase tracking-widest text-slate-500"><?= $stat['label'] ?></div>
                            <div class="text-3xl font-semibold tracking-tighter mt-1 metric-value"><?= $stat['value'] ?></div>
                        </div>
                        <i class="fa-solid fa-<?= $stat['icon'] ?> text-3xl text-slate-200"></i>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Category Scores -->
            <div class="mb-8">
                <h2 class="font-semibold text-lg mb-3 flex items-center gap-x-2"><i class="fa-solid fa-chart-bar text-emerald-600"></i> Category Breakdown</h2>
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <?php foreach ($analysis['category_scores'] as $cat => $cat_score): 
                        $cat_label = ucwords(str_replace('_', ' ', $cat));
                        $cat_color = $cat_score >= 85 ? 'emerald' : ($cat_score >= 70 ? 'amber' : 'rose');
                    ?>
                    <div class="bg-white border rounded-3xl p-5">
                        <div class="flex justify-between items-baseline">
                            <div class="font-medium"><?= $cat_label ?></div>
                            <div class="text-2xl font-semibold tracking-tighter text-<?= $cat_color ?>-600"><?= $cat_score ?><span class="text-base font-normal text-slate-400">/100</span></div>
                        </div>
                        <div class="mt-3 h-2 bg-slate-100 rounded-full overflow-hidden">
                            <div class="h-2 bg-<?= $cat_color ?>-500 rounded-full transition-all" style="width: <?= $cat_score ?>%"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Recommendations / Issues -->
            <div class="mb-8">
                <div class="flex items-center justify-between mb-3">
                    <h2 class="font-semibold text-lg flex items-center gap-x-2"><i class="fa-solid fa-tasks text-emerald-600"></i> Prioritized Recommendations</h2>
                    <div class="text-xs px-3 py-1 bg-white border rounded-2xl text-slate-500"><?= count($analysis['issues']) ?> findings</div>
                </div>

                <?php if (empty($analysis['issues'])): ?>
                    <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 rounded-3xl p-8 text-center">
                        <i class="fa-solid fa-check-circle text-4xl mb-3"></i>
                        <p class="font-medium">Excellent! No major issues detected in this scan.</p>
                        <p class="text-sm mt-1 text-emerald-600/80">Continue monitoring with regular audits.</p>
                    </div>
                <?php else: ?>
                    <div class="bg-white border rounded-3xl overflow-hidden">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="bg-slate-50 border-b text-left">
                                    <th class="px-6 py-4 font-medium text-slate-600 w-24">Severity</th>
                                    <th class="px-6 py-4 font-medium text-slate-600">Finding</th>
                                    <th class="px-6 py-4 font-medium text-slate-600">Recommendation</th>
                                    <th class="px-6 py-4 font-medium text-slate-600 hidden lg:table-cell">Impact</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <?php foreach ($analysis['issues'] as $issue): 
                                    $level_color = match($issue['level']) {
                                        'critical' => 'rose',
                                        'high' => 'orange',
                                        'medium' => 'amber',
                                        default => 'slate'
                                    };
                                ?>
                                <tr class="finding-row">
                                    <td class="px-6 py-4 align-top">
                                        <span class="inline-flex items-center px-3 py-1 rounded-2xl text-xs font-semibold bg-<?= $level_color ?>-100 text-<?= $level_color ?>-700">
                                            <?= strtoupper($issue['level']) ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 align-top font-medium"><?= htmlspecialchars($issue['title']) ?></td>
                                    <td class="px-6 py-4 align-top text-slate-600"><?= htmlspecialchars($issue['recommendation']) ?></td>
                                    <td class="px-6 py-4 align-top text-xs text-slate-500 hidden lg:table-cell"><?= htmlspecialchars($issue['impact']) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Detailed Extracted Data -->
            <details class="mb-8 group">
                <summary class="cursor-pointer flex items-center gap-x-2 text-sm font-medium text-slate-600 hover:text-slate-900 mb-2">
                    <i class="fa-solid fa-chevron-right group-open:rotate-90 transition-transform"></i>
                    <span>View Raw Extracted Data (for debugging &amp; transparency)</span>
                </summary>
                <div class="bg-white border rounded-3xl p-6 text-xs font-mono overflow-auto max-h-[420px]">
                    <pre class="text-slate-700"><?= htmlspecialchars(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
                </div>
            </details>

            <!-- robots & sitemap summary if present -->
            <?php if (!empty($extra['robots_sitemap'])): ?>
            <div class="bg-white border rounded-3xl p-6 mb-8">
                <h3 class="font-semibold mb-4 flex items-center gap-x-2"><i class="fa-solid fa-robot"></i> robots.txt &amp; Sitemap Summary</h3>
                <div class="grid md:grid-cols-2 gap-6 text-sm">
                    <div>
                        <div class="font-medium mb-1">robots.txt <span class="text-xs px-2 py-0.5 rounded bg-<?= $extra['robots_sitemap']['has_robots'] ? 'emerald' : 'rose' ?>-100 text-<?= $extra['robots_sitemap']['has_robots'] ? 'emerald' : 'rose' ?>-700"><?= $extra['robots_sitemap']['has_robots'] ? 'Found' : 'Not found' ?></span></div>
                        <?php if ($extra['robots_sitemap']['robots_content']): ?>
                            <pre class="bg-slate-900 text-emerald-400 p-4 rounded-2xl text-[10px] overflow-auto max-h-48"><?= htmlspecialchars($extra['robots_sitemap']['robots_content']) ?></pre>
                        <?php endif; ?>
                    </div>
                    <div>
                        <div class="font-medium mb-1">Sitemap.xml <span class="text-xs px-2 py-0.5 rounded bg-<?= $extra['robots_sitemap']['has_sitemap'] ? 'emerald' : 'rose' ?>-100 text-<?= $extra['robots_sitemap']['has_sitemap'] ? 'emerald' : 'rose' ?>-700"><?= $extra['robots_sitemap']['has_sitemap'] ? 'Found' : 'Not found / not parsed' ?></span></div>
                        <?php if (!empty($extra['robots_sitemap']['sitemap_sample'])): ?>
                            <ul class="text-xs space-y-1 text-slate-600">
                                <?php foreach (array_slice($extra['robots_sitemap']['sitemap_sample'], 0, 6) as $loc): ?>
                                    <li class="truncate">• <?= htmlspecialchars($loc) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="text-center text-xs text-slate-400 mt-8">
                Generated by <?= APP_NAME ?> v<?= VERSION ?> • Pure PHP • No external APIs used in this scan<br>
                For advanced AI-powered analysis, full-site crawling, and PDF/Excel reports, consider the original Claude SEO skill or extending this tool.
            </div>
        </div>
    </div>

    <script>
        // Tailwind script
        function initializeTailwind() {
            document.documentElement.style.setProperty('--accent', '#10b981');
        }
        window.onload = initializeTailwind;
        
        // Keyboard shortcut
        document.addEventListener('keydown', function(e) {
            if (e.metaKey && e.key === "Enter") {
                e.preventDefault();
                window.location.href = '?';
            }
        });
    </script>
</body>
</html>
<?php
    return ob_get_clean();
}

// ========== MAIN LOGIC ==========
$report_html = '';
$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw_url = $_POST['url'] ?? '';
    $include_extra = !empty($_POST['include_robots_sitemap']);

    $url = sanitize_url($raw_url);

    if (!is_valid_url($url)) {
        $error = 'Please enter a valid HTTP or HTTPS URL.';
    } else {
        // Fetch main page
        $fetch_result = fetch_page($url);

        if (!$fetch_result['success']) {
            $error = 'Failed to fetch the page. ' . ($fetch_result['error'] ?: 'HTTP status: ' . ($fetch_result['info']['http_code'] ?? 'unknown'));
        } else {
            $final_url = $fetch_result['info']['url'] ?? $url;
            $html = $fetch_result['html'];
            $load_time = $fetch_result['info']['total_time'] ?? 0;

            // Parse
            $seo_data = parse_seo_data($html, $final_url);

            // Analyze
            $analysis = analyze_seo($seo_data);

            // Extra crawl if requested
            $extra_data = [];
            if ($include_extra) {
                $extra_data['robots_sitemap'] = fetch_robots_and_sitemap($final_url);
            }
            $extra_data['load_time'] = $load_time;
            $extra_data['http_code'] = $fetch_result['info']['http_code'] ?? 0;

            // Render beautiful report
            $report_html = render_report($seo_data, $analysis, $extra_data);
            $success = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?> • Self-Hosted SEO Crawler &amp; Reporter</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&amp;family=Space+Grotesk:wght@500;600&amp;display=swap');
        body { font-family: 'Inter', system_ui, sans-serif; }
        .section-header { font-family: 'Space Grotesk', sans-serif; letter-spacing: -.03em; }
    </style>
</head>
<body class="bg-slate-950 text-slate-200">
    <div class="min-h-screen flex flex-col">
        <!-- Top nav -->
        <div class="border-b border-white/10 bg-slate-900/70 backdrop-blur-lg sticky top-0 z-50">
            <div class="max-w-5xl mx-auto px-8 py-5 flex justify-between items-center">
                <div class="flex items-center gap-x-3">
                    <div class="w-9 h-9 bg-emerald-500 rounded-2xl flex items-center justify-center">
                        <i class="fa-solid fa-searchengin text-white text-3xl"></i>
                    </div>
                    <div>
                        <div class="font-semibold text-2xl tracking-tighter"><?= APP_NAME ?></div>
                        <div class="text-[10px] text-emerald-400/70 -mt-1">PHP • Self-hosted</div>
                    </div>
                </div>
                <div class="text-xs px-4 py-1.5 rounded-3xl bg-white/5 border border-white/10 text-emerald-400 flex items-center gap-x-2">
                    <i class="fa-solid fa-globe fa-fw"></i>
                    <span>Ready for your Plesk / PHP server</span>
                </div>
            </div>
        </div>

        <div class="flex-1 flex items-center justify-center px-6 py-12">
            <div class="max-w-2xl w-full">
                <?php if (!$success): ?>
                    <!-- Landing / Form -->
                    <div class="text-center mb-10">
                        <div class="inline-flex items-center gap-x-2 px-4 py-1 rounded-3xl bg-emerald-500/10 text-emerald-400 text-xs font-medium tracking-widest mb-4">
                            CONVERTED FROM CLAUDE-SEO • PURE PHP
                        </div>
                        <h1 class="text-6xl font-semibold tracking-tighter section-header mb-3">Crawl. Analyze.<br>Optimize.</h1>
                        <p class="text-xl text-slate-400 max-w-md mx-auto">Self-hosted SEO auditor with beautiful reports. No API keys. No subscriptions. Just PHP.</p>
                    </div>

                    <?php if ($error): ?>
                        <div class="mb-6 bg-rose-500/10 border border-rose-500/30 text-rose-400 px-5 py-3 rounded-3xl text-sm flex items-start gap-x-3">
                            <i class="fa-solid fa-exclamation-triangle mt-0.5"></i>
                            <div><?= htmlspecialchars($error) ?></div>
                        </div>
                    <?php endif; ?>

                    <div class="bg-white/5 border border-white/10 backdrop-blur-xl rounded-3xl p-8">
                        <form method="POST" class="space-y-6">
                            <div>
                                <label class="block text-sm font-medium text-slate-300 mb-2">Website URL to Audit</label>
                                <div class="relative">
                                    <div class="absolute left-5 top-4 text-emerald-400"><i class="fa-solid fa-link fa-lg"></i></div>
                                    <input type="text" name="url" required 
                                           class="w-full bg-slate-900 border border-white/10 focus:border-emerald-500 transition-colors text-white placeholder:text-slate-500 pl-12 pr-5 py-4 text-lg rounded-2xl outline-none"
                                           placeholder="https://example.com or yourdomain.com" 
                                           value="<?= htmlspecialchars($_POST['url'] ?? '') ?>">
                                </div>
                                <p class="text-xs text-slate-500 mt-2 ml-1">Works best with public HTTPS sites. Single-page detailed analysis.</p>
                            </div>

                            <div class="flex items-center gap-x-3 pl-1">
                                <input type="checkbox" name="include_robots_sitemap" id="extra" class="w-4 h-4 accent-emerald-500" checked>
                                <label for="extra" class="text-sm text-slate-300 cursor-pointer">Also check robots.txt &amp; sitemap.xml summary</label>
                            </div>

                            <button type="submit"
                                    class="w-full flex items-center justify-center gap-x-3 bg-emerald-500 hover:bg-emerald-600 active:bg-emerald-700 transition-all text-white font-semibold py-4 rounded-2xl text-lg shadow-xl shadow-emerald-500/30">
                                <i class="fa-solid fa-play fa-fw"></i>
                                <span>Run SEO Audit</span>
                            </button>
                        </form>
                    </div>

                    <div class="mt-8 text-center text-xs text-slate-500 max-w-md mx-auto">
                        Features: Title/Meta analysis • Heading structure • Image alt coverage • Internal linking • JSON-LD schema detection • 
                        Prioritized recommendations • Professional report • Print to PDF ready
                    </div>
                <?php else: ?>
                    <!-- Report is rendered inside the function above and echoed -->
                    <?= $report_html ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="text-center py-6 text-xs text-slate-500 border-t border-white/10">
            Built for developers who want control • Extendable • MIT-style spirit • 
            <a href="https://github.com/AgricIDaniel/claude-seo" target="_blank" class="hover:text-emerald-400 underline">Original Claude SEO</a>
        </div>
    </div>
</body>
</html>