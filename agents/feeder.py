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
from knowledge.seo_corpus import CORPUS, by_field, stats  # noqa: E402
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

    # 0) Clean up any duplicates already in the brain (e.g. from an older
    #    Feeder that re-taught the same text).
    try:
        cleaned = feeder._get("/dedupe")
        if cleaned.get("removed"):
            print(f"      cleaned {cleaned['removed']} duplicate memories.")
    except Exception:
        pass

    # 1) Fast initial pass — teach the whole curriculum so the Professor is
    #    fully knowledgeable within seconds.
    print("      seeding the curriculum …")
    for field, kind, text, tags in CORPUS:
        if (feeder.directive or {}).get("paused"):
            break
        feeder.remember(text, tags=list(tags) + [field, kind])
        time.sleep(0.05)
    print("      curriculum seeded — now teaching to the team's needs.\n")

    # per-field rotation cursors so re-feeding a field cycles its material
    cursors = {}
    tick = 0
    while True:
        d = feeder.directive or {}
        if d.get("paused"):
            time.sleep(0.5)
            continue

        # 2) Ask the brain what the team needs most, right now.
        analysis = feeder.analyze()
        focus = d.get("focus")

        target_field = None
        gap_topic = None
        if focus:
            target_field = focus[0]
        elif analysis.get("recommendations"):
            rec = analysis["recommendations"][0]
            target_field = rec.get("field")
            if rec.get("priority") == "gap":
                gap_topic = rec.get("need")

        # 3a) A real gap outside the curriculum → try to source it from the web.
        if gap_topic:
            found = sourcing.source(gap_topic, target_field)
            if found:
                for text, tags in found:
                    feeder.remember(text, tags=tags)
                feeder._post("/gap_filled", {"topic": gap_topic})
                print(f"  [feeder] sourced & taught for gap: {gap_topic!r}")
            else:
                # can't source it offline — surface the need
                print(f"  [feeder] gap needs external sourcing: {gap_topic!r}")

        # 3b) Teach toward the weakest / focused field from the curriculum.
        field = target_field if (target_field and by_field(target_field)) else None
        if not field:
            fields = [c["field"] for c in analysis.get("weakest_fields", [])] or None
            field = fields[0] if fields else CORPUS[tick % len(CORPUS)][0]
        entries = by_field(field)
        if entries:
            i = cursors.get(field, 0) % len(entries)
            cursors[field] = i + 1
            _f, kind, text, tags = entries[i]
            feeder.remember(text, tags=list(tags) + [field, kind])

        # 4) Filer upkeep — let stale knowledge fade every so often.
        tick += 1
        if tick % 25 == 0:
            feeder._post("/decay", {})

        time.sleep(max(0.05, float(d.get("pace", 1.5))))


if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        print("\n  🌫  Feeder resting.\n")
