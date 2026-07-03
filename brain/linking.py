"""Neuron formation & meaning — how memories relate.

Two pieces of knowledge relate when they mean the same thing. We approximate
meaning with an IDF-weighted vector-space model over expanded keyword sets:

  * keywords()  — content words + tags, minus stop-words
  * expand()    — pull in synonyms so different phrasings collide
                  ("competing pages" ↔ "cannibalization")
  * cosine()    — IDF-weighted cosine similarity: rare, meaningful terms
                  count more than common ones

This is deliberately dependency-free (pure standard library) and a real step
up from raw word overlap. It also leaves a clean seam: swap cosine() for a
call to a real embedding model when an API key is available, and everything
downstream (recall, teach, auto-linking, dedup) gets sharper for free.
"""

import math
import re

_STOP = {
    "the", "a", "an", "and", "or", "but", "if", "then", "else", "of", "to",
    "in", "on", "at", "for", "with", "as", "by", "is", "are", "was", "were",
    "be", "been", "being", "it", "its", "this", "that", "these", "those",
    "i", "you", "he", "she", "we", "they", "them", "his", "her", "our",
    "your", "my", "me", "us", "do", "does", "did", "so", "not", "no", "yes",
    "from", "into", "about", "up", "down", "out", "over", "under", "than",
    "can", "will", "would", "should", "could", "have", "has", "had", "what",
    "which", "who", "when", "where", "why", "how", "all", "any", "each",
    "per", "use", "uses", "using", "your", "their",
}

_WORD = re.compile(r"[a-z0-9]+")

# Domain synonyms — different phrasings map onto a shared canonical token, so
# "competing pages" and "keyword cannibalization" land near each other. Add
# freely; this is where a lot of the "it understands me" quality lives.
_SYNONYMS = {
    "cannibalization": {"competing", "compete", "overlap", "duplicate"},
    "cannibalize": {"cannibalization"},
    "cwv": {"vitals", "lcp", "inp", "cls", "pagespeed", "speed", "performance"},
    "performance": {"speed", "fast", "slow", "cwv"},
    "serp": {"ranking", "rank", "position", "results"},
    "ranking": {"rank", "serp", "position", "visibility"},
    "ctr": {"clickthrough", "clicks", "click"},
    "backlink": {"link", "links", "authority", "referring"},
    "authority": {"backlink", "trust", "strength"},
    "crawl": {"index", "indexation", "crawlability", "budget"},
    "indexation": {"crawl", "index", "coverage"},
    "canonical": {"duplicate", "duplication"},
    "intent": {"purpose", "informational", "transactional", "commercial"},
    "conversion": {"convert", "cro", "sales", "revenue", "checkout"},
    "revenue": {"sales", "conversion", "money"},
    "keyword": {"query", "term", "search"},
    "content": {"copy", "text", "article", "guide"},
    "meta": {"description", "snippet", "title"},
    "schema": {"structured", "markup", "richresults"},
    "feed": {"shopping", "merchant", "product"},
    "roas": {"return", "spend", "profit", "bidding"},
    "negatives": {"negative", "exclude", "wasted"},
    "category": {"collection", "listing"},
    "product": {"item", "sku", "part"},
    "redirect": {"301", "moved", "migration"},
    "migration": {"redirect", "301", "replatform"},
    "eeat": {"trust", "expertise", "authority", "experience"},
}


def keywords(content, tags=None):
    """The meaningful tokens for a piece of knowledge."""
    tokens = set()
    for w in _WORD.findall((content or "").lower()):
        if len(w) >= 3 and w not in _STOP:
            tokens.add(w)
    for t in tags or []:
        t = str(t).strip().lower()
        if t:
            tokens.add(t)
    return tokens


def expand(tokens):
    """Grow a token set with domain synonyms, so phrasing differences meet."""
    out = set(tokens)
    for t in list(tokens):
        if t in _SYNONYMS:
            out |= _SYNONYMS[t]
        # reverse: if t appears as a synonym value, add the canonical key
        for key, vals in _SYNONYMS.items():
            if t in vals:
                out.add(key)
    return out


def cosine(a, b, idf=None):
    """IDF-weighted cosine similarity between two expanded token sets (0..1).

    `idf` is a callable term -> weight. Without it, every term weighs 1,
    which reduces to plain cosine over sets.
    """
    if not a or not b:
        return 0.0
    w = idf or (lambda _t: 1.0)
    inter = a & b
    if not inter:
        return 0.0
    dot = sum(w(t) ** 2 for t in inter)
    na = math.sqrt(sum(w(t) ** 2 for t in a))
    nb = math.sqrt(sum(w(t) ** 2 for t in b))
    if na == 0 or nb == 0:
        return 0.0
    return dot / (na * nb)


def vector(content, tags=None):
    """The expanded token 'vector' for a memory — what we compare on."""
    return expand(keywords(content, tags))


# Backwards-compatible name used elsewhere; now meaning-aware.
def similarity(a, b, idf=None):
    return cosine(a, b, idf)


def strongest_links(new_vec, existing, idf=None, threshold=0.12, top_n=6):
    """Which existing nodes a new memory should synapse to.

    `existing` is an iterable of (node_id, token_vector). Returns
    (node_id, weight) for the strongest matches above threshold.
    """
    scored = []
    for node_id, vec in existing:
        s = cosine(new_vec, vec, idf)
        if s >= threshold:
            scored.append((node_id, round(s, 4)))
    scored.sort(key=lambda x: x[1], reverse=True)
    return scored[:top_n]
