"""The Overseer — a brain doctor that plugs into your brains, watches how
they run, tunes what it safely can, and writes an improvement report.

It works across *multiple* brains (e.g. an SEO brain and a stock-market
brain), so you can see at a glance how each is doing and where it's stuck.

    python agents/overseer.py                      # observe (default brain)
    BRAINS="http://nas:8000,http://other:8000" python agents/overseer.py
    python agents/overseer.py --fix                # also apply the safe fixes
    python agents/overseer.py --watch 300          # keep watching every 5 min

What it does:
  * DIAGNOSE — collaboration, untrained skills, unfilled gaps, idle agents,
    duplicate bloat, a stalled Feeder.
  * TUNE (safe, only with --fix) — de-duplicate, register gap fields, nudge
    the Feeder toward the weakest declared skill.
  * REPORT — writes overseer_report.md: the health of each brain, the actions
    taken, and concrete *code/design* improvements for a human or coding agent
    to apply next. (It never edits code itself — that stays reviewed.)
"""

import os
import sys
import time

sys.path.insert(0, os.path.join(os.path.dirname(__file__), "..", "client"))
from brain_client import BrainClient  # noqa: E402

REPORT = os.path.join(os.path.dirname(__file__), "..", "overseer_report.md")


def brains_from_env():
    raw = os.environ.get("BRAINS") or os.environ.get("BRAIN_URL", "http://127.0.0.1:8000")
    return [u.strip() for u in raw.split(",") if u.strip()]


def diagnose(c):
    """Return (findings, actions, code_suggestions) for one brain."""
    findings, actions, code = [], [], []
    stats = c.stats()
    metrics = c.metrics()
    training = c.training()["fields"]
    dirs = c.directives()
    analysis = c.analyze()

    declared = {d.get("field") for d in dirs.values() if d.get("field")}
    minds = {m["agent"]: m for m in metrics.get("minds", [])}
    ci = metrics.get("collaboration_index", 0)

    # 1) siloed team
    if metrics.get("total_links", 0) >= 12 and ci < 0.2:
        findings.append(("collaboration", "warn",
                         f"team is siloed (collaboration {int(ci*100)}%) — agents barely build on each other"))
        code.append("Consider auto-linking across agents more aggressively, or have the Keeper route every request through ≥2 specialists.")

    # 2) skills declared but not trained
    known = {f["field"]: f["known"] for f in training}
    for field in declared:
        if known.get(field, 0) == 0:
            findings.append(("untrained", "warn", f"skill '{field}' is declared but has no knowledge yet"))
            actions.append(("focus_feeder", field))

    # 3) unfilled demand
    gaps = analysis.get("gaps", [])
    hot = [g for g in gaps if g.get("misses", 0) >= 3]
    for g in hot:
        findings.append(("gap", "info", f"team keeps needing '{g['topic']}' ({g['misses']}×) — not in the brain"))
        if g.get("field"):
            actions.append(("add_field", g["field"]))
    if hot and "sourcing" not in os.environ.get("_seen", ""):
        code.append("Set BRAIN_SEARCH_URL so the Feeder can source unmet needs from the web automatically.")

    # 4) idle agents (declared specialists that contribute nothing)
    for name, d in dirs.items():
        if d.get("group") == "specialist" and minds.get(name, {}).get("neurons", 0) == 0:
            findings.append(("idle", "info", f"agent '{name}' has connected but contributed nothing yet"))

    # 5) duplicate bloat (a fingerprint: far more synapses than neurons warrant)
    n, s = stats.get("neurons", 0), stats.get("synapses", 0)
    if n > 50 and s > n * 4:
        findings.append(("bloat", "warn", f"possible duplicate bloat ({n} neurons, {s} synapses)"))
        actions.append(("dedupe", None))

    # 6) stalled feeder
    if declared and n == 0:
        findings.append(("stalled", "warn", "skills are declared but the brain is empty — is the Feeder running?"))

    return findings, actions, code, {"neurons": n, "synapses": s,
                                     "collaboration": ci, "minds": len(minds),
                                     "skills": len(declared)}


def apply(c, actions):
    done = []
    weakest_focused = False
    for kind, arg in actions:
        try:
            if kind == "dedupe":
                r = c.dedupe(); done.append(f"deduped ({r.get('removed',0)} removed)")
            elif kind == "add_field":
                c.add_field(arg); done.append(f"registered field '{arg}'")
            elif kind == "focus_feeder" and not weakest_focused:
                c.steer("feeder", focus=[arg]); weakest_focused = True
                done.append(f"nudged Feeder to focus '{arg}'")
        except Exception as e:
            done.append(f"could not {kind} {arg}: {e}")
    return done


def run(fix):
    lines = ["# Overseer report", ""]
    for url in brains_from_env():
        lines.append(f"## Brain @ {url}")
        try:
            c = BrainClient("overseer", url)
        except Exception as e:
            lines.append(f"- ⚠️ could not reach: {e}\n"); continue
        findings, actions, code, summary = diagnose(c)
        lines.append(f"- **{summary['neurons']} neurons · {summary['synapses']} synapses · "
                     f"{summary['minds']} minds · {summary['skills']} skills · "
                     f"{int(summary['collaboration']*100)}% collaboration**")
        lines.append("")
        lines.append("**Findings**")
        if findings:
            for _t, sev, msg in findings:
                mark = {"warn": "⚠️", "info": "•"}.get(sev, "•")
                lines.append(f"- {mark} {msg}")
        else:
            lines.append("- ✅ healthy — nothing to flag")
        if fix and actions:
            lines.append("")
            lines.append("**Actions taken**")
            for d in apply(c, actions):
                lines.append(f"- ✔ {d}")
        elif actions:
            lines.append("")
            lines.append("**Safe fixes available** (run with --fix)")
            for kind, arg in actions:
                lines.append(f"- {kind} {arg or ''}".rstrip())
        if code:
            lines.append("")
            lines.append("**Code / design improvements to consider**")
            for s in code:
                lines.append(f"- {s}")
        lines.append("")

    report = "\n".join(lines)
    with open(REPORT, "w") as fh:
        fh.write(report)
    print(report)
    print(f"\n(report written to {os.path.abspath(REPORT)})")


def main():
    fix = "--fix" in sys.argv
    watch = None
    if "--watch" in sys.argv:
        i = sys.argv.index("--watch")
        watch = int(sys.argv[i + 1]) if i + 1 < len(sys.argv) else 300
    while True:
        run(fix)
        if not watch:
            break
        time.sleep(watch)


if __name__ == "__main__":
    main()
