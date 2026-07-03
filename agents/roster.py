"""Load the agent roster into a running brain — the roles, written out.

Each agent's role and standing instruction is stored as its *directive*,
so it's live from the first second and can be changed at any time without a
restart. The roster is data (roster.json); add as many agents as you like
and re-run this — it merges, so re-running is always safe.

    python main.py                 # terminal 1 — the brain
    python agents/roster.py        # load / reload every role
    python agents/roster.py --list # show who's defined and how they're steered

Add one agent while everything is running:
    python agents/roster.py --add ads_scripts "Ads Scripts" specialist sea \
        "Own automated Google Ads scripts. Recall first, contribute tagged 'sea'."
"""

import json
import os
import sys

sys.path.insert(0, os.path.join(os.path.dirname(__file__), "..", "client"))
from brain_client import BrainClient  # noqa: E402

URL = os.environ.get("BRAIN_URL", "http://127.0.0.1:8000")
# domain-specific roster if present (roster.<domain>.json), else the default
_DOMAIN = os.environ.get("BRAIN_DOMAIN", "seo")
_here = os.path.dirname(__file__)
_domain_roster = os.path.join(_here, f"roster.{_DOMAIN}.json")
ROSTER = _domain_roster if os.path.exists(_domain_roster) else os.path.join(_here, "roster.json")


def load_file():
    with open(ROSTER) as fh:
        return json.load(fh)["agents"]


def directive_of(a):
    """The steering directive an agent starts life with."""
    d = {
        "role": a.get("title", a["name"]),
        "group": a.get("group", "agent"),
        "instruction": a.get("instruction", ""),
    }
    if a.get("field"):
        d["field"] = a["field"]
    if a.get("pace") is not None:
        d["pace"] = a["pace"]
    return d


def apply(control, agents):
    for a in agents:
        control.steer(a["name"], **directive_of(a))
    return len(agents)


def main():
    control = BrainClient("roster", URL)   # a client just for steering

    args = sys.argv[1:]
    if args and args[0] == "--list":
        directives = control._get("/directives")
        for name, d in sorted(directives.items()):
            if name in ("*", "roster"):
                continue
            print(f"  {name:14s} [{d.get('group','?')}] {d.get('role','')}")
            if d.get("instruction"):
                print(f"                 → {d['instruction']}")
        return

    if args and args[0] == "--add":
        name, title, group, field, instruction = (args[1:6] + [""] * 5)[:5]
        a = {"name": name, "title": title, "group": group,
             "field": field or None, "instruction": instruction}
        control.steer(name, **directive_of(a))
        print(f"  added/updated '{name}' live → {title}")
        return

    agents = load_file()
    n = apply(control, agents)
    core = [a["name"] for a in agents if a.get("group") == "core"]
    spec = [a["name"] for a in agents if a.get("group") == "specialist"]
    print(f"\n  🧠  Roster loaded into the brain at {URL}")
    print(f"      {n} agents defined and steerable live")
    print(f"      core:        {', '.join(core)}")
    print(f"      specialists: {', '.join(spec)}")
    print("      change any of them any time:  python agents/roster.py --add ...\n")


if __name__ == "__main__":
    main()
