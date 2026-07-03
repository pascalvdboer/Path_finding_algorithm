"""Where the Feeder finds *new* knowledge to fill a gap.

By default the brain teaches from its built-in curriculum. To let the Feeder
actually go and source the best current material from the web for a gap, plug
in a search endpoint — no code change needed, just an environment variable:

    BRAIN_SEARCH_URL = https://your-search-proxy/seo?q={topic}

That URL should return JSON: a list of {"text": "...", "tags": ["field", ...]}.
Point it at any search/RAG service you like (an internal proxy over a web
search API, your own vector store, etc.). When it's not set, sourcing is a
safe no-op and the brain simply reports the gap.

This keeps the brain dependency-free and self-contained, while leaving one
clean seam for real-time, needs-driven web sourcing.
"""

import json
import os
import urllib.parse
import urllib.request


def configured():
    return bool(os.environ.get("BRAIN_SEARCH_URL"))


def source(topic, field=None, limit=3):
    """Fetch new knowledge for a gap. Returns a list of (text, tags).

    Safe by default: returns [] unless BRAIN_SEARCH_URL is configured.
    """
    url_tmpl = os.environ.get("BRAIN_SEARCH_URL")
    if not url_tmpl or not topic:
        return []
    url = url_tmpl.replace("{topic}", urllib.parse.quote(topic))
    if "{topic}" not in url_tmpl:
        sep = "&" if "?" in url_tmpl else "?"
        url = f"{url_tmpl}{sep}q={urllib.parse.quote(topic)}"
    try:
        with urllib.request.urlopen(url, timeout=15) as resp:
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
        out.append((text, tags))
    return out
