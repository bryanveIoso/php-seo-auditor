# PHP SEO Auditor

Self-hosted PHP SEO website crawler and professional report generator.

**Inspired by** [claude-seo](https://github.com/AgricIDaniel/claude-seo) — converted to pure PHP for easy hosting on Plesk, cPanel, or any PHP server.

## Features

- ✅ URL form input + one-click crawl
- ✅ Technical SEO checks (title, meta, headings, canonical, viewport, robots)
- ✅ On-page content analysis (word count, heading structure)
- ✅ Images alt-text coverage & internal/external links analysis
- ✅ Basic JSON-LD structured data detection
- ✅ Rule-based scoring + prioritized recommendations (critical/high/medium/low)
- ✅ Beautiful modern dashboard report (Tailwind CSS)
- ✅ Optional robots.txt + sitemap.xml summary
- ✅ Print / Save as PDF ready
- ✅ Zero external dependencies (pure PHP + cURL + DOMDocument)

## Quick Start (Plesk / PHP Hosting)

1. Upload `index.php` to a folder on your PHP server (e.g. `/seo-auditor/`)
2. Visit `https://yourdomain.com/seo-auditor/`
3. Enter any URL and click **Run SEO Audit**
4. Get a professional, client-ready report instantly

### Requirements
- PHP 8.1+
- Extensions: `curl`, `dom`, `mbstring`, `openssl` (standard on most hosts)

## What It Does

This tool performs a deep single-page SEO audit and generates a polished report similar in spirit to advanced tools like claude-seo, but fully self-hosted and running on plain PHP.

It replaces the need for external SEO SaaS for basic-to-intermediate audits and gives you full control + privacy.

## Limitations (Honest)
- Static HTML parsing only (no JavaScript rendering like Playwright)
- Rule-based analysis (no AI agents or LLM synthesis)
- Single page by default (extendable to multi-page)

For full AI-powered multi-agent audits, use the original Claude SEO skill.

## Extending the Tool

Easy to extend:
- Add mPDF for real PDF export
- Integrate Google PageSpeed Insights API
- Add Puppeteer microservice for SPA support
- Add GD-based JPEG table export (like csv-to-jpeg-table skill)

## License

MIT-style — free to use, modify, and host.

Created with Grok for Jehzeel / Big Wall Digital.