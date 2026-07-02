# 🧠 The Agent Brain

A shared **cortex** your agents plug into — a living place where they
**store** knowledge, **teach** and **learn** from each other, and **hand off**
information instantly. Every memory becomes a neuron; related memories wire
themselves together into synapses; and the whole thing is drawn as a
**living neural map** where pulses of information travel down the wires in
real time as agents think together.

Built on the Python **standard library only** — no pip installs, no build
step. Clone it and run it.

![concept](https://img.shields.io/badge/deps-zero-7fd0ff) ![python](https://img.shields.io/badge/python-3.11-7fd0ff)

---

## What it gives your agents

| Need | How the brain does it |
|------|-----------------------|
| **A place of connection & storage** | A persistent knowledge graph in SQLite. Nothing is lost between runs. |
| **Teach** | `remember(content, tags)` — the memory auto-wires to every related memory already in the brain. |
| **Learn** | `recall(query)` — pulls back the most related memories, ranked. |
| **Work together / fast handoffs** | `handoff(to, content)` is pushed to the target agent **instantly** over a live stream — no polling. |
| **Fast, visual output** | A browser neural map lights up the moment anything happens: new neurons, forming synapses, recalls chaining through memory, handoffs pulsing agent-to-agent. |

## Run it — one command

```bash
python run.py
```

That starts the brain, loads the agent roster, starts the Feeder, opens the
**live dashboard** in your browser, and streams a team of requests through
it — so you just watch. Press Ctrl+C to stop everything. No installs needed:
it uses only the Python standard library.

## Or run the pieces yourself

Four terminals (or run them in the background) — the brain, the roster, the
Feeder brain-stem, and a request stream:

```bash
python main.py                 # 1) the brain + dashboard   (open the URL)
python agents/roster.py        # 2) load every agent's role
python agents/feeder.py        # 3) the Feeder — constant inflow from the stem
python examples/live_demo.py   # 4) requests flowing through the whole team
```

Watch the dashboard: warm signals rise from the brain stem, the Keeper's
region lights and arcs out to the specialists, and finished work flows through
Soul and Literate — your team, thinking together inside the brain.

Steer any agent while it runs, no restart:

```bash
python agents/roster.py --add ads "Ads Scripts" specialist sea "Own ad scripts."
curl -s localhost:8000/steer -d '{"agent":"feeder","focus":["technical"]}'
```

## Plug your own agent in

Copy `client/brain_client.py` into your agent and:

```python
from brain_client import BrainClient

me = BrainClient("scout", "http://127.0.0.1:8000")

me.remember("The API rate limit is 60 req/min", tags=["api", "limits"])
knowledge = me.recall("rate limit")              # learn what the swarm knows
me.handoff("planner", "Batch requests to stay under the limit")

# react the instant a teammate hands you something:
me.listen(lambda ev: print("got:", ev["content"]))
```

That's the whole surface. Any process that speaks HTTP can join — the four
verbs below are plain HTTP if you're not in Python.

## The wire protocol (any language)

| Verb | Endpoint | Body / query |
|------|----------|--------------|
| register | `POST /register` | `{"name": "scout"}` |
| teach | `POST /remember` | `{"agent","content","tags":[]}` |
| learn | `GET /recall` | `?q=...&k=5` |
| handoff | `POST /handoff` | `{"from","to","content","tags":[]}` |
| live stream | `GET /events` | Server-Sent Events (snapshot + activity) |
| snapshot | `GET /state` | full graph JSON |

## How it's put together

```
main.py                  wake the brain
brain/
  server.py              HTTP + Server-Sent-Events hub (stdlib only)
  store.py               the cortex — SQLite knowledge graph
  linking.py             neuron formation (how memories wire together)
  events.py              live pub/sub for instant handoffs
  web/index.html         the neural visualiser (canvas, real-time)
client/brain_client.py   the link agents plug in with
examples/                a small swarm coming to life
```

**How memories wire themselves:** each memory is reduced to a set of
keywords (content words + tags). Two memories form a synapse when their
keyword sets overlap (weighted Jaccard) above a threshold; the overlap
becomes the synapse's weight, so stronger relationships pull tighter in the
map. It's deliberately simple and dependency-free — and it's the one place
to upgrade next (swap in vector embeddings for semantic wiring).

## Where to take it next

- **Semantic synapses** — replace keyword overlap in `linking.py` with
  embedding similarity for meaning-level connections.
- **Forgetting & salience** — decay unused neurons, strengthen reused ones.
- **Auth & spaces** — per-team brains, private vs shared memory.
- **Multi-machine** — run with `--host 0.0.0.0` so agents anywhere connect.
