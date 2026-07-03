# 🧠 The Agent Brain — start here

A shared **brain** your team of agents plugs into. It stores everything the
team learns, wires related knowledge together, hands work between agents
instantly, and acts as their **master teacher / professor** — actively
teaching each agent the best of its field. You watch it all happen in a live,
glowing brain.

Everything runs on the **Python standard library** — no installs.

## It starts empty and becomes whatever your team needs

The brain is **not** pre-loaded with a fixed subject. It boots **empty**. What
it should master isn't known in advance — it emerges from the **team that uses
it**:

- Agents connect and **declare their skills** (their fields).
- The **Feeder / Professor** then trains *those* fields — the skills the team
  has and needs — and fills gaps on demand.
- Fields **expand** as you add agents, as the director adds a field, or as
  demand surfaces one. Nothing is hard-coded.

So the same engine runs an **SEO team**, a **stock-market desk**, or anything
else. Pick a ready knowledge pack with an env var, or bring your own:

```bash
python run.py                       # SEO team (default)
BRAIN_DOMAIN=stockmarket python run.py   # a stock-market desk — totally different skills
```

A "domain pack" is just optional teaching material: `knowledge/<name>_corpus.py`
+ `agents/roster.<name>.json`. No engine code changes.

---

## 1. See it work (2 minutes, on your PC)

```bash
python run.py
```

Your browser opens the brain and the **imta-technics.shop team runs live**:
the Feeder rises warm from the brain stem, the Professor teaches each
specialist the best of its field, the Keeper assigns work, specialists light
their regions, and answers finish through Soul and Literate. Ctrl+C stops it.

> On a Mac use `python3 run.py`. Nothing to install.

## 2. How the brain teaches (the professor)

The brain isn't a passive notebook — it's a teacher. Any agent can ask it to
teach the best of a field:

```python
from brain_client import BrainClient
me = BrainClient("onpage", "http://127.0.0.1:8000")

for lesson in me.ask_professor(field="onpage")["lessons"]:
    print(lesson["kind"], "—", lesson["content"])
```

The Feeder continuously sources and ranks the best knowledge, tools and
trainings (see `knowledge/seo_corpus.py` — add your own freely), and the
Professor serves the most valuable of it back on demand.

## 3. Connect your real team

Give your team the **`team_kit/`** folder. It contains:

- `AGENT_INSTRUCTIONS.md` — the habits every agent follows (be taught →
  recall → remember → hand off). Paste into each agent.
- `brain_client.py` — the connector each agent uses.
- `connect.py` — verify the brain is reachable.
- `README.md` + `.env.example` — set `BRAIN_URL` to the running brain.

Each agent gets a **name** and a **field** and points at the brain's address.
That's it — they now share one growing brain.

## 4. Make it always-on (for the whole team)

Run it on your **NAS or a server** so it's always there. See
**`docs/DEPLOY_NAS.md`** — one command with Docker:

```bash
docker compose up -d          # http://<your-nas-ip>:8000
```

Cloud (Cowork) agents reach it through your NAS's VPN/tunnel; LAN agents use
its address directly.

## 5. Steer agents while they run

Change any agent's focus, pace, or role live — no restart:

```bash
python agents/roster.py                    # load every role
python agents/roster.py --add ads "Ads Scripts" specialist sea "Own ad scripts."
curl -s localhost:8000/steer -d '{"agent":"feeder","focus":["technical"]}'
```

## The cast

| Agent | Role |
|---|---|
| **Feeder / Professor** | Sources & teaches the best knowledge — the brain stem, always feeding |
| **Keeper** | Oversees incoming work, assigns the right specialists |
| **Filer** | Organises every memory so it's findable by meaning |
| **Specialists** | onpage · technical · keyword · sea · content · linkbuilding · local · analytics · ecommerce · cro |
| **Soul** | Gives the answer soul & meaning |
| **Literate** | Sets it in perfect context |

Add or change agents anytime in `agents/roster.json`.

## Watch & self-improve — the Overseer

A brain doctor that plugs into one or more brains, sees how they run, safely
tunes what it can, and writes an improvement report (working *and* code):

```bash
python agents/overseer.py                 # observe the default brain
BRAINS="http://nas:8000,http://x:8000" python agents/overseer.py   # several at once
python agents/overseer.py --fix           # also apply the safe fixes
python agents/overseer.py --watch 300     # keep watching every 5 minutes
```

It flags siloed teams, untrained skills, unfilled demand, idle agents,
duplicate bloat and a stalled Feeder; auto-applies safe fixes (de-dupe,
register gap fields, nudge the Feeder); and writes `overseer_report.md` with
concrete code/design improvements to apply next. It never edits code itself —
that stays reviewed.

## Check everything works

```bash
python tests/test_brain.py     # store, professor, steering, metrics, dynamic
                               # fields, security, GET-write, dedupe, live API
```

## What's real today vs. next

**Working now:** the brain, the professor/teaching, live steering, the roster,
the live dashboard, the team kit, one-command run, Docker/NAS deploy, tests.

**Next, when you want it:** wiring your *live Cowork agents* in (deploy to the
NAS, point them at it with the team kit), and upgrading recall to **semantic
search** (embeddings) so the professor understands meaning, not just keywords.
