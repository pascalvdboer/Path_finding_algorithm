"""A full team working a real request through the brain — end to end.

Feeder stocks the base, the Keeper assigns specialists, they recall the
shared knowledge and contribute findings, then Soul and Literate finish it.
Prints the flow and the collaboration the brain measured.

    python main.py                 # terminal 1
    python examples/team_run.py    # terminal 2
"""

import os
import sys

sys.path.insert(0, os.path.join(os.path.dirname(__file__), "..", "client"))
sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))
from brain_client import BrainClient          # noqa: E402
from knowledge.seo_corpus import CORPUS        # noqa: E402

URL = os.environ.get("BRAIN_URL", "http://127.0.0.1:8000")
REQUEST = "Improve organic revenue for the brake-parts category page"


def main():
    feeder = BrainClient("feeder", URL)
    keeper = BrainClient("keeper", URL)
    team = {f: BrainClient(f, URL) for f in ["onpage", "technical", "keyword", "content"]}
    soul = BrainClient("soul", URL)
    literate = BrainClient("literate", URL)

    print("① feeder stocks the base knowledge…")
    for field, kind, text, tags in CORPUS[:16]:
        feeder.remember(text, tags=list(tags) + [field, kind])

    print(f"② keeper receives: {REQUEST!r}")
    for f in team:
        keeper.handoff(f, f"Work the {f} angle of: {REQUEST}")

    print("③ specialists recall shared knowledge, then contribute findings…")
    for f, agent in team.items():
        hits = agent.recall(REQUEST + " " + f)["results"]
        top = hits[0]["node"]["content"] if hits else "(nothing yet)"
        print(f"   {f:9s} learned ← {top[:64]}")
        agent.remember(f"[{f}] plan for brake-parts page informed by shared knowledge",
                       tags=[f, "plan", "brake-parts"])

    print("④ hand the combined work to soul, then literate…")
    first = next(iter(team))
    team[first].handoff("soul", "Draft plan ready — give it meaning")
    soul.handoff("literate", "Meaning added — set it in perfect context")
    literate.remember("Final: prioritized brake-parts SEO plan, in context",
                      tags=["final", "brake-parts"])

    m = keeper.stats()
    col = keeper._get("/metrics")
    print("\n── result ──")
    print(f"   brain now holds {m['neurons']} neurons, {m['synapses']} synapses across {m['agents']} minds")
    print(f"   collaboration index: {int(col['collaboration_index']*100)}%  "
          f"({col['cross_links']}/{col['total_links']} links bridge two agents)")
    print("   top collaborations:", ", ".join(
        f"{p['pair']} ({p['links']})" for p in col["pairs"][:3]))


if __name__ == "__main__":
    main()
