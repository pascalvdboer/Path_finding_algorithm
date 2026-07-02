"""A little swarm coming to life inside the brain.

Start the brain first, open the visualiser, then run this:

    python main.py                    # terminal 1  (open the URL it prints)
    python examples/two_agents_demo.py   # terminal 2

Watch neurons appear, synapses wire related ideas together, recalls chain
through memory, and handoffs pulse from one agent to another.
"""

import os
import sys
import time

sys.path.insert(0, os.path.join(os.path.dirname(__file__), "..", "client"))
from brain_client import BrainClient  # noqa: E402

URL = os.environ.get("BRAIN_URL", "http://127.0.0.1:8000")


def slow(s=0.7):
    time.sleep(s)


def main():
    scout = BrainClient("scout", URL)
    planner = BrainClient("planner", URL)
    builder = BrainClient("builder", URL)

    # The planner listens for anything handed to it, and reacts instantly.
    def on_handoff(ev):
        print(f"  [planner] woke on handoff: {ev['content']!r}")
        planner.remember("Plan step: " + ev["content"], tags=["plan"])

    planner.listen(on_handoff)

    print("scout explores and teaches the brain...")
    scout.remember("The target API rate limit is 60 requests per minute",
                   tags=["api", "limits"])
    slow()
    scout.remember("Auth uses OAuth2 bearer tokens that expire in 1 hour",
                   tags=["api", "auth"])
    slow()
    scout.remember("Batching lets you fetch 50 records per request",
                   tags=["api", "performance"])
    slow()

    print("builder adds what it knows...")
    builder.remember("Retry with exponential backoff on HTTP 429",
                     tags=["api", "limits", "resilience"])
    slow()
    builder.remember("Cache bearer tokens to avoid re-auth every call",
                     tags=["auth", "performance"])
    slow()

    print("planner learns before deciding (recall)...")
    hits = planner.recall("how do we stay under the rate limit")
    for h in hits["results"]:
        print(f"    ↳ {h['score']:.2f}  {h['node']['content']}")
    slow(1)

    print("scout hands a conclusion straight to the planner...")
    scout.handoff("planner",
                  "Combine batching with backoff to stay under 60 req/min",
                  tags=["api", "limits", "performance"])
    slow(1)

    builder.handoff("planner",
                    "Reuse cached tokens so auth never blocks a batch",
                    tags=["auth", "performance"])
    slow(1)

    print("\nbrain stats:", scout.stats())
    print("Leave the visualiser open — the memories persist in brain.db.")


if __name__ == "__main__":
    main()
