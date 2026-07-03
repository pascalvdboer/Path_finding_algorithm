"""Where the Feeder finds *new, trustworthy* knowledge to fill a need.

The value of the whole brain depends on the quality of what goes in. So the
Feeder sources from **reliable, primary sources** — official manuals, docs,
standards, reputable courses — not random blogs. You configure both the
search endpoint and the trusted sources; no code change:

    BRAIN_SEARCH_URL     = https://your-search-bridge?q={topic}
    BRAIN_TRUSTED_SOURCES = developers.google.com, schema.org, web.dev, ...

The search bridge should return JSON: a list of
    {"text": "...", "tags": ["field", ...], "source": "developers.google.com"}
Prefer results *from the trusted sources*; the brain records each memory's
`source:` so provenance is kept and trusted knowledge can be valued higher.

When BRAIN_SEARCH_URL is not set, sourcing is a safe no-op and the brain
simply reports the need — nothing is invented.
"""

import json
import os
import urllib.parse
import urllib.request


def configured():
    return bool(os.environ.get("BRAIN_SEARCH_URL"))


def trusted():
    raw = os.environ.get("BRAIN_TRUSTED_SOURCES", "")
    return [d.strip().lower() for d in raw.split(",") if d.strip()]


def _domain(item):
    src = (item.get("source") or item.get("url") or "").lower()
    src = src.replace("https://", "").replace("http://", "").split("/")[0]
    return src or None


def source(topic, field=None, limit=3):
    """Fetch new, trustworthy knowledge for a need. Returns (text, tags),
    each tagged with its source for provenance. Safe no-op unless a search
    endpoint is configured."""
    url_tmpl = os.environ.get("BRAIN_SEARCH_URL")
    if not url_tmpl or not topic:
        return []
    q = urllib.parse.quote(topic)
    url = url_tmpl.replace("{topic}", q) if "{topic}" in url_tmpl else \
        f"{url_tmpl}{'&' if '?' in url_tmpl else '?'}q={q}"
    tl = trusted()
    if tl:
        url += ("&" if "?" in url else "?") + "trusted=" + urllib.parse.quote(",".join(tl))
    try:
        with urllib.request.urlopen(url, timeout=20) as resp:
            data = json.loads(resp.read().decode("utf-8"))
    except Exception:
        return []
    out = []
    for item in (data or [])[:limit]:
        text = (item.get("text") or "").strip()
        if not text:
            continue
        tags = list(item.get("tags") or [])
        if field and field not in tags:
            tags.append(field)
        tags.append("sourced")
        dom = _domain(item)
        if dom:
            tags.append(f"source:{dom}")
            # a small quality signal: mark knowledge from a trusted source
            if any(dom.endswith(t) or t in dom for t in tl):
                tags.append("trusted")
        out.append((text, tags))
    return out
