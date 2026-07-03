"""The Feeder / Professor — the brain stem & master teacher.

Not a fixed playlist: it *analyses what the team needs* and feeds that first.
Each cycle it asks the brain what's missing (knowledge gaps + weakest-covered
fields) and teaches toward those, so the agents get exactly what will lift
their work. When a need falls outside the built-in curriculum, it tries to
source it from the web (if a search endpoint is configured — see
brain/sourcing.py). It also keeps the memory sharp by decaying stale
knowledge.

Fully steerable while running (focus / pace / pause), no restart:

    curl -s $URL/steer -d '{"agent":"feeder","focus":["technical"]}'

Run it:
    python main.py                       # terminal 1
    python agents/feeder.py              # terminal 2  (the brain stem)
"""

import os
import sys
import time

sys.path.insert(0, os.path.join(os.path.dirname(__file__), "..", "client"))
sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

from brain_client import BrainClient          # noqa: E402
from knowledge import CORPUS, by_field, stats  # noqa: E402  (domain pack via BRAIN_DOMAIN)
from brain import sourcing                     # noqa: E402

URL = os.environ.get("BRAIN_URL", "http://127.0.0.1:8000")


def main():
    feeder = BrainClient("feeder", URL)

    def on_change(d):
        bits = []
        if d.get("paused"):
            bits.append("PAUSED")
        if d.get("focus"):
            bits.append("focus=" + ",".join(d["focus"]))
        if d.get("pace"):
            bits.append(f"pace={d['pace']}s")
        print(f"  [feeder] re-steered → {', '.join(bits) or 'defaults'}")
    feeder.follow_steering(on_change)

    print(f"  🌫  Feeder / Professor online → {URL}")
    print(f"      curriculum: {stats()}")
    print(f"      web sourcing: {'ON' if sourcing.configured() else 'off (set BRAIN_SEARCH_URL to enable)'}\n")

    # Clean up any duplicates already in the brain (e.g. from an older Feeder).
    try:
        cleaned = feeder._get("/dedupe")
        if cleaned.get("removed"):
            print(f"      cleaned {cleaned['removed']} duplicate memories.")
    except Exception:
        pass

    print("      empty brain — waiting for the team to bring their skills.\n")

    def needed_fields(d, analysis):
        """The skills the brain should actually master right now — declared by
        the agents that have connected, plus anything demand (gaps) surfaced."""
        need = set()
        try:
            for dr in (feeder._get("/directives") or {}).values():
                if dr.get("field"):
                    need.add(dr["field"])
        except Exception:
            pass
        for rec in analysis.get("recommendations", []):
            if rec.get("field"):
                need.add(rec["field"])
        if d.get("focus"):
            focus = set(d["focus"])
            need = (need & focus) or focus
        return need

    trained = set()      # fields we've already given a first training pass
    cursors = {}
    tick = 0
    said_waiting = False
    while True:
        d = feeder.directive or {}
        if d.get("paused"):
            time.sleep(0.5); continue

        analysis = feeder.analyze()
        need = needed_fields(d, analysis)

        # Nothing declared yet → the brain stays empty until the team arrives.
        if not need:
            if not said_waiting:
                print("  [feeder] no skills declared yet — brain stays empty.")
                said_waiting = True
            time.sleep(2.0); continue
        said_waiting = False

        # Register the needed fields so they appear (and can grow from zero).
        for f in need:
            feeder.add_field(f)

        # First time we see a needed field: train it. Use the built-in pack if
        # it happens to have material for that skill; otherwise it will be
        # filled on demand from gaps / the web / the agents themselves.
        for f in list(need):
            if f in trained:
                continue
            entries = by_field(f)
            if entries:
                for _f, kind, text, tags in entries:
                    feeder.remember(text, tags=list(tags) + [f, kind])
                    time.sleep(0.04)
                print(f"  [feeder] trained '{f}' — {len(entries)} pieces from the pack.")
            else:
                print(f"  [feeder] '{f}' has no built-in material — will source it on demand.")
            trained.add(f)

        # Make sure the data the agents need is actually there: source new,
        # trustworthy knowledge — for real demand gaps AND for skills that are
        # still too thin — from reliable/primary sources.
        if sourcing.configured():
            for rec in analysis.get("recommendations", []):
                is_gap = rec.get("priority") == "gap"
                topic = rec["need"] if is_gap else f"{rec.get('field','')} authoritative guide manual"
                found = sourcing.source(topic, rec.get("field"))
                if found:
                    for text, tags in found:
                        feeder.remember(text, tags=tags)
                    if is_gap:
                        feeder._post("/gap_filled", {"topic": rec["need"]})
                    prov = next((t for t in (found[0][1]) if t.startswith("source:")), "web")
                    print(f"  [feeder] sourced {len(found)} for '{rec.get('field') or rec['need']}' ({prov})")
                    break
                elif is_gap:
                    feeder._post("/gap_filled", {"topic": rec["need"]})
        elif analysis.get("recommendations"):
            # No source connected — the brain can't grow from nothing. Say so.
            if tick % 20 == 0:
                print("  [feeder] needs a source to grow — set BRAIN_SEARCH_URL "
                      "(+ BRAIN_TRUSTED_SOURCES) so I can fetch from manuals/docs.")

        # Keep a needed field's material fresh (steady, light).
        f = sorted(need)[tick % len(need)]
        entries = by_field(f)
        if entries:
            i = cursors.get(f, 0) % len(entries)
            cursors[f] = i + 1
            _f, kind, text, tags = entries[i]
            feeder.remember(text, tags=list(tags) + [f, kind])

        tick += 1
        if tick % 25 == 0:
            feeder._post("/decay", {})

        time.sleep(max(0.05, float(d.get("pace", 1.5))))


if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        print("\n  🌫  Feeder resting.\n")
