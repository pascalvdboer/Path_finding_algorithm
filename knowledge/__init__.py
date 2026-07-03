"""Domain packs — what the brain is an expert in is just configuration.

The engine (brain, Feeder, Professor, Filer, dashboard) is domain-agnostic.
A *domain pack* is the knowledge + fields for one subject. Pick one with an
environment variable — no code changes:

    BRAIN_DOMAIN=seo           (default)  → knowledge/seo_corpus.py
    BRAIN_DOMAIN=stockmarket              → knowledge/stockmarket_corpus.py

Add your own by dropping a `knowledge/<name>_corpus.py` that exposes
CORPUS, FIELDS, by_field(), stats() — and an `agents/roster.<name>.json`.
The fields the team masters then expand from *that* pack, plus anything the
director adds or the agents discover on demand.
"""

import importlib
import os

DOMAIN = os.environ.get("BRAIN_DOMAIN", "seo")

try:
    _mod = importlib.import_module(f"knowledge.{DOMAIN}_corpus")
except ModuleNotFoundError:
    DOMAIN = "seo"
    _mod = importlib.import_module("knowledge.seo_corpus")

CORPUS = _mod.CORPUS
FIELDS = _mod.FIELDS
by_field = _mod.by_field
stats = _mod.stats
