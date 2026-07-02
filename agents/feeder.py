"""The Feeder — the brain stem.

A constant inflow of the best SEO knowledge, tools, and trainings into the
brain. This is not a one-time seed: it runs continuously, forming the stable
base every working agent draws from. It keeps the foundation warm by cycling
through the corpus and re-affirming connections.

It is fully steerable while running — change it from anywhere without a
restart:

    # from any agent / the control panel / curl:
    ctrl.steer("feeder", focus=["technical", "sea"])   # narrow the inflow
    ctrl.steer("feeder", pace=0.4)                      # faster rush
    ctrl.steer("feeder", paused=True)                   # hold
    ctrl.steer("feeder", paused=False)                  # resume

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
from knowledge.seo_corpus import CORPUS, FIELDS, stats  # noqa: E402

URL = os.environ.get("BRAIN_URL", "http://127.0.0.1:8000")


def main():
    feeder = BrainClient("feeder", URL)

    # React the instant someone re-steers the brain stem.
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

    print(f"  🌫  Feeder (brain stem) online → {URL}")
    print(f"      corpus: {stats()}")
    print("      steer it live, e.g.:  curl -s $URL/steer -d '{\"agent\":\"feeder\",\"focus\":[\"technical\"]}'\n")

    idx = 0
    while True:
        d = feeder.directive or {}

        if d.get("paused"):
            time.sleep(0.5)
            continue

        # a directive may narrow the inflow to certain fields
        focus = d.get("focus")
        corpus = [e for e in CORPUS if not focus or e[0] in focus]
        if not corpus:
            time.sleep(0.5)
            continue

        field, kind, text, tags = corpus[idx % len(corpus)]
        idx += 1
        # tag with field + kind so the Filer / working agents can find it
        feeder.remember(text, tags=list(tags) + [field, kind])

        # pace: seconds between pulses of knowledge (the "rush" speed)
        pace = float(d.get("pace", 0.8))
        time.sleep(max(0.05, pace))


if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        print("\n  🌫  Feeder resting.\n")
