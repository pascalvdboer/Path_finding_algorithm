"""Run the team live so you can watch it in the brain dashboard.

Open the brain in a browser first, then run this. It keeps a gentle stream
going — the Feeder feeds from the stem, and requests cycle through the
Keeper → specialists → Soul → Literate — so the brain stays alive on screen.
Ctrl+C to stop.

    python main.py                    # terminal 1
    # open the URL it prints in a browser
    python examples/live_demo.py      # terminal 2  — watch it work
"""

import os
import sys
import time

sys.path.insert(0, os.path.join(os.path.dirname(__file__), "..", "client"))
sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))
from brain_client import BrainClient          # noqa: E402
from knowledge.seo_corpus import CORPUS        # noqa: E402

URL = os.environ.get("BRAIN_URL", "http://127.0.0.1:8000")
SHOP = os.environ.get("SHOP", "imta-technics.shop")
REQUESTS = [
    "Rank the brake-parts category page for organic revenue",
    "Recover rankings after the imta-technics.shop migration",
    "Lower cost per click on the hydraulics Shopping campaign",
    "Win featured snippets for the parts installation guides",
    "Fix indexation bloat from faceted product filters",
    "Optimise product pages for high-intent part-number searches",
    "Build topical authority around the machine-parts category",
]


def main():
    feeder = BrainClient("feeder", URL)
    keeper = BrainClient("keeper", URL)
    team = {f: BrainClient(f, URL) for f in
            ["onpage", "technical", "keyword", "sea", "content"]}
    soul = BrainClient("soul", URL)
    literate = BrainClient("literate", URL)

    print(f"live demo — the {SHOP} team working in the brain. Ctrl+C to stop.\n")
    fi = ri = 0
    while True:
        # the Feeder keeps feeding the base from the stem
        field, kind, text, tags = CORPUS[fi % len(CORPUS)]
        feeder.remember(text, tags=list(tags) + [field, kind])
        fi += 1
        time.sleep(0.6)

        # every few feeds, run a full request through the team
        if fi % 4 == 0:
            req = REQUESTS[ri % len(REQUESTS)]; ri += 1
            print(f"→ {req}")
            picks = ["onpage", "technical", "keyword", "content"]
            for f in picks:
                keeper.handoff(f, f"Work the {f} angle of: {req}")
                time.sleep(0.4)
            for f in picks:
                team[f].ask_professor(field=f)          # the brain teaches the specialist
                team[f].recall(req + " " + f)            # then it learns the team's context
                team[f].remember(f"[{f}] finding for: {req}", tags=[f, "finding"])
                time.sleep(0.4)
            picks and team[picks[0]].handoff("soul", "Draft ready — give it meaning")
            time.sleep(0.5)
            soul.handoff("literate", "Meaning added — set it in context")
            time.sleep(0.5)
            literate.remember(f"Final answer for: {req}", tags=["final"])
            time.sleep(0.8)


if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        print("\nstopped.")
