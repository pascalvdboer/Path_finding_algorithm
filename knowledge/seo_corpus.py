"""The Professor's curriculum — the base knowledge the Feeder streams in.

Curated SEO expertise, tools, and trainings, organised by field. This is the
raw material the brain teaches its agents from: the Feeder pushes it in as a
constant inflow, and the Professor (see brain/store.py `teach`) serves the
best of it back to any agent that asks.

Each entry: (field, kind, text, tags)
  kind:  'fact'      — a best-practice principle
         'tool'      — an instrument agents can reach for
         'training'  — a method / play to follow

Tuned for an e-commerce technical-parts shop (imta-technics.shop): product
and category pages, technical specs, and a mix of organic + paid search.
Add freely — the Feeder teaches whatever is here, and the Professor ranks it.
"""

FIELDS = [
    "onpage", "technical", "keyword", "sea", "content", "linkbuilding",
    "local", "analytics", "ecommerce", "cro",
]

CORPUS = [
    # ---- on-page SEO -------------------------------------------------
    ("onpage", "fact", "Title tag: unique, under 60 chars, primary keyword near the front, brand at the end", ["title"]),
    ("onpage", "fact", "Meta description drives click-through, not ranking — write it to be clicked, include the keyword", ["meta", "ctr"]),
    ("onpage", "fact", "Exactly one H1 per page; H2/H3 mirror the real content hierarchy", ["headings", "structure"]),
    ("onpage", "fact", "Descriptive image alt text helps accessibility and image search — describe the part, not 'image1'", ["images", "alt"]),
    ("onpage", "fact", "Internal links pass authority — link from strong pages to the products you want to rank", ["internal-links", "authority"]),
    ("onpage", "fact", "Clean, readable URLs: /category/product-name, no session IDs or deep nesting", ["urls"]),
    ("onpage", "fact", "Put the primary keyword in the first 100 words and in at least one subheading", ["copy"]),
    ("onpage", "fact", "Breadcrumbs improve crawlability and add structured-data context to deep pages", ["breadcrumbs", "structure"]),
    ("onpage", "training", "On-page audit play: crawl, then check title, H1, meta, canonical, and internal links per template", ["audit", "process"]),
    ("onpage", "tool", "Screaming Frog SEO Spider — crawl the whole shop to find missing titles, duplicates, broken links", ["tool", "crawl"]),

    # ---- technical SEO ----------------------------------------------
    ("technical", "fact", "Core Web Vitals targets: LCP under 2.5s, INP under 200ms, CLS under 0.1", ["cwv", "performance"]),
    ("technical", "fact", "Canonical tags resolve duplicate content across filtered/sorted product URLs", ["canonical", "duplicate"]),
    ("technical", "fact", "XML sitemap + clean robots.txt steer crawl budget to money pages, away from faceted noise", ["crawl-budget", "sitemap"]),
    ("technical", "fact", "Product structured data (schema.org/Product, offers, AggregateRating) unlocks rich results", ["schema", "rich-results"]),
    ("technical", "fact", "Faceted navigation can explode into millions of thin URLs — control with rules and noindex", ["faceted", "indexation"]),
    ("technical", "fact", "Serve one canonical version: pick https + non-www (or www) and 301 the rest", ["https", "redirects"]),
    ("technical", "fact", "Lazy-load below-the-fold images but never the LCP image — it must load immediately", ["performance", "images"]),
    ("technical", "fact", "hreflang tags map language/region variants and stop them competing", ["international", "hreflang"]),
    ("technical", "training", "Indexation check: compare sitemap URLs vs Search Console 'indexed' to find gaps and bloat", ["indexation", "process"]),
    ("technical", "tool", "Google Search Console — coverage, Core Web Vitals, and which queries the shop actually ranks for", ["tool", "gsc"]),
    ("technical", "tool", "PageSpeed Insights / Lighthouse — measure and diagnose Core Web Vitals per template", ["tool", "performance"]),

    # ---- keyword research -------------------------------------------
    ("keyword", "fact", "Match every page to a single dominant search intent: informational, commercial, or transactional", ["intent"]),
    ("keyword", "fact", "Long-tail, specific part queries convert higher at lower difficulty than broad heads", ["long-tail", "conversion"]),
    ("keyword", "fact", "Keyword cannibalization splits authority — one target keyword per page, consolidate overlaps", ["cannibalization"]),
    ("keyword", "fact", "Map keywords to the funnel: category pages for commercial, guides for informational", ["mapping", "funnel"]),
    ("keyword", "fact", "Model numbers and part codes are high-intent queries — target them on product pages", ["product"]),
    ("keyword", "fact", "Study the SERP before targeting a keyword — its format tells you the intent Google rewards", ["serp", "intent"]),
    ("keyword", "training", "Keyword-to-URL map: one row per target keyword, its page, intent, and current rank", ["mapping", "process"]),
    ("keyword", "tool", "Ahrefs / Semrush — keyword volume, difficulty, and the competitors ranking for each", ["tool", "research"]),

    # ---- paid search (SEA) ------------------------------------------
    ("sea", "fact", "Quality Score lowers CPC when ad copy, keyword, and landing page all align on intent", ["quality-score", "cpc"]),
    ("sea", "fact", "Negative keywords stop wasted spend on irrelevant or non-buying queries", ["negatives", "spend"]),
    ("sea", "fact", "Shopping campaigns need a clean, complete product feed — titles and GTINs drive matching", ["shopping", "feed"]),
    ("sea", "fact", "Bid to target ROAS on transactional terms; cap spend on broad research terms", ["bidding", "roas"]),
    ("sea", "fact", "Landing an ad on the exact product, not the homepage, protects conversion rate and Quality Score", ["landing-page", "conversion"]),
    ("sea", "fact", "Feed title order matters: brand + product type + key attribute + model beats keyword stuffing", ["shopping", "feed"]),
    ("sea", "training", "Search-term report weekly: mine for new negatives and new converting keywords", ["optimization", "process"]),
    ("sea", "tool", "Google Ads + Merchant Center — campaigns, the product feed, and Shopping diagnostics", ["tool", "google-ads"]),

    # ---- content ----------------------------------------------------
    ("content", "fact", "E-E-A-T: show real experience, expertise, authority, and trust — vital for technical products", ["eeat", "trust"]),
    ("content", "fact", "Topic clusters + a pillar page build topical authority around a product category", ["clusters", "authority"]),
    ("content", "fact", "Buying guides and spec comparisons capture research-stage traffic and feed internal links", ["guides", "funnel"]),
    ("content", "fact", "Unique product descriptions beat manufacturer boilerplate — duplicated specs don't rank", ["product", "duplicate"]),
    ("content", "fact", "Answer the real questions buyers ask — pull them from search suggestions and support tickets", ["research"]),
    ("content", "fact", "Refresh and re-publish decaying pages before writing new ones — it's higher ROI", ["freshness"]),
    ("content", "training", "Cluster plan: one pillar per category, supporting guides answering real buyer questions", ["clusters", "process"]),

    # ---- link building ----------------------------------------------
    ("linkbuilding", "fact", "Relevant, authoritative links move rankings more than raw link volume", ["links", "authority"]),
    ("linkbuilding", "fact", "Digital PR and supplier/manufacturer relationships earn natural, on-topic links", ["digital-pr", "outreach"]),
    ("linkbuilding", "fact", "Reclaim unlinked brand and product mentions — the easiest links to win", ["reclamation"]),
    ("linkbuilding", "fact", "Being a distributor for known brands earns 'where to buy' links from manufacturer sites", ["ecommerce", "outreach"]),
    ("linkbuilding", "tool", "Ahrefs Backlink audit — find toxic links and spot competitors' best link sources", ["tool", "backlinks"]),

    # ---- local ------------------------------------------------------
    ("local", "fact", "A complete, categorised Google Business Profile wins local pack and 'near me' visibility", ["gbp", "local-pack"]),
    ("local", "fact", "Consistent NAP (name, address, phone) across citations reinforces local trust", ["nap", "citations"]),
    ("local", "fact", "Reviews with photos and owner replies lift both ranking and click-through in the map pack", ["reviews"]),

    # ---- analytics --------------------------------------------------
    ("analytics", "fact", "Track organic revenue and assisted conversions, not just sessions — traffic isn't the goal", ["ga4", "revenue"]),
    ("analytics", "fact", "Tag every campaign with UTM parameters so channels are attributable in GA4", ["utm", "attribution"]),
    ("analytics", "fact", "Watch rankings, clicks and revenue together — a ranking with no clicks means wrong intent", ["measurement"]),
    ("analytics", "training", "Monthly SEO report: rankings, organic revenue, CWV, indexation — one dashboard", ["reporting", "process"]),
    ("analytics", "tool", "GA4 + Looker Studio — one shared dashboard the whole team reads from", ["tool", "dashboard"]),

    # ---- ecommerce fundamentals -------------------------------------
    ("ecommerce", "fact", "Keep discontinued product URLs alive: 301 to the closest match or keep for spares demand", ["lifecycle", "redirects"]),
    ("ecommerce", "fact", "Category pages are the biggest organic asset — give them intro copy and clean facets", ["category"]),
    ("ecommerce", "fact", "Out-of-stock isn't 404: keep the page, show alternatives, retain the rankings", ["stock", "ux"]),
    ("ecommerce", "fact", "Compatibility/fitment data ('fits model X') captures huge high-intent long-tail demand", ["product", "fitment"]),

    # ---- conversion (CRO) -------------------------------------------
    ("cro", "fact", "Clear stock status, delivery time, and price near the buy button lift add-to-cart rate", ["ux", "product"]),
    ("cro", "fact", "Technical buyers need specs, datasheets and compatibility up front — not buried in tabs", ["product", "trust"]),
    ("cro", "fact", "Trust signals (returns, warranty, secure checkout) matter most on higher-priced parts", ["trust"]),
    ("cro", "training", "Fix the leakiest funnel step first — measure add-to-cart, checkout, and purchase drop-off", ["process", "funnel"]),
]


def by_field(field):
    return [e for e in CORPUS if e[0] == field]


def stats():
    kinds = {}
    for _f, kind, _t, _tags in CORPUS:
        kinds[kind] = kinds.get(kind, 0) + 1
    return {"entries": len(CORPUS), "fields": len(FIELDS), "by_kind": kinds}
