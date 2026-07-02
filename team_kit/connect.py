"""Verify an agent can reach the brain and use it. Run this first.

    BRAIN_URL=http://<brain-host>:8000 python connect.py

If it prints a recalled memory back, the connection works and your agents
are ready to plug in.
"""

import os
from brain_client import BrainClient

URL = os.environ.get("BRAIN_URL", "http://127.0.0.1:8000")


def main():
    print(f"connecting to the brain at {URL} …")
    me = BrainClient("tester", URL)
    print("  connected. brain stats:", me.stats())

    me.remember("Connection test: the team kit works", tags=["test"])
    hits = me.recall("team kit works")
    print("  recall came back with:",
          hits["results"][0]["node"]["content"] if hits["results"] else "(nothing)")
    print("\n✓ the brain is reachable — your agents can use it.")


if __name__ == "__main__":
    main()
