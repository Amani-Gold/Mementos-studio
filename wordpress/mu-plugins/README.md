# Mementos SEO — Collections (mu-plugin)

Fixes the `/collections/*` configurator pages, which today reach Google as a bare iframe: no meta
description, canonical, Open Graph or structured data, and no sitemap entry.

What it does, all driven by the live `wp-json/mementos/v1/config` catalog (cached 1 h, refreshed on product save):

| Change | Before | After |
| --- | --- | --- |
| `<title>` | `Luxury Collection — Mementos Studio` | `Luxury Collection — Handcrafted Photo Albums in Bahrain \| Mementos Studio` |
| Meta description, canonical, robots, OG/Twitter | none | generated per collection |
| JSON-LD | none | BreadcrumbList + WebPage + Product with AggregateOffer (BHD low/high from the size table), linked to AIOSEO's `/#organization` |
| `/product/luxury-albums/` etc. | 302 → collection | 301 → collection |
| AIOSEO sitemap | lists the redirecting `/product/` URLs, omits `/collections/` | lists the 5 `/collections/` URLs, drops the redirecting ones |

The configurator, cart and checkout are untouched — the plugin only adds tags before `</head>`.

## Install

1. Upload `mementos-seo-collections.php` to `wp-content/mu-plugins/` (create the folder if missing).
2. Purge the LiteSpeed cache.
3. Check: `curl -s https://mementos-studio.com/collections/luxury-albums/ | grep mementos-seo-collections`
   and run the URL through https://search.google.com/test/rich-results.
4. Resubmit `sitemap.xml` in Search Console.

Rollback: delete the file and purge the cache. Nothing else is stored.

## Not covered (needs the album-designer plugin source)

Visible, crawlable collection copy (H1, materials, size/price table, FAQs) has to be server-rendered by the
`mementos-album-designer` plugin itself; adding hidden text behind the full-screen iframe would be cloaking.
