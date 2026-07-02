"""Neuron formation: how memories auto-wire into synapses.

Two pieces of knowledge become connected when they share meaning. We
approximate meaning with a keyword set (content words + explicit tags)
and measure overlap with a weighted Jaccard similarity. Above a
threshold, a synapse forms; its weight is the similarity.
"""

import re

# Very small English stop-word set — enough to keep links meaningful
# without pulling in any dependency.
_STOP = {
    "the", "a", "an", "and", "or", "but", "if", "then", "else", "of", "to",
    "in", "on", "at", "for", "with", "as", "by", "is", "are", "was", "were",
    "be", "been", "being", "it", "its", "this", "that", "these", "those",
    "i", "you", "he", "she", "we", "they", "them", "his", "her", "our",
    "your", "my", "me", "us", "do", "does", "did", "so", "not", "no", "yes",
    "from", "into", "about", "up", "down", "out", "over", "under", "than",
    "can", "will", "would", "should", "could", "have", "has", "had", "what",
    "which", "who", "when", "where", "why", "how", "all", "any", "each",
}

_WORD = re.compile(r"[a-z0-9]+")


def keywords(content, tags=None):
    """Return the set of meaningful tokens for a piece of knowledge.

    Explicit tags are weighted implicitly by always being included and
    lower-cased; content words shorter than 3 chars and stop-words are
    dropped.
    """
    tokens = set()
    for w in _WORD.findall((content or "").lower()):
        if len(w) >= 3 and w not in _STOP:
            tokens.add(w)
    for t in tags or []:
        t = str(t).strip().lower()
        if t:
            tokens.add(t)
    return tokens


def similarity(a, b):
    """Weighted Jaccard similarity between two keyword sets (0..1)."""
    if not a or not b:
        return 0.0
    inter = a & b
    if not inter:
        return 0.0
    union = a | b
    return len(inter) / len(union)


def strongest_links(new_keywords, existing, threshold=0.12, top_n=6):
    """Pick which existing nodes a new memory should synapse to.

    `existing` is an iterable of (node_id, keyword_set). Returns a list
    of (node_id, weight) for the strongest matches above threshold,
    capped at top_n so hubs don't explode.
    """
    scored = []
    for node_id, kw in existing:
        s = similarity(new_keywords, kw)
        if s >= threshold:
            scored.append((node_id, round(s, 4)))
    scored.sort(key=lambda x: x[1], reverse=True)
    return scored[:top_n]
