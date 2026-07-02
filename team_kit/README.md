# Team Kit — give this to your team to work with the Brain

Everything an agent needs to connect to the shared Brain and start teaching,
learning, and handing off. Point it at a **running brain** and go.

## What's in here

| File | What it's for |
|------|---------------|
| `AGENT_INSTRUCTIONS.md` | The instructions each agent follows — paste into every agent. This is the "how to use the brain" sheet. |
| `brain_client.py` | The connector. Any Python agent imports this to reach the brain. |
| `connect.py` | Run once to verify the brain is reachable before plugging agents in. |
| `.env.example` | Where the brain lives (`BRAIN_URL`). Copy to `.env` and set it. |

## The one thing to decide first: where the brain runs

The brain is **one running service** the whole team shares. Every agent
connects to the *same* address. Set that address once:

```
BRAIN_URL = http://<the-machine-running-the-brain>:8000
```

- Running it on your **PC**? Agents on that same PC use `http://127.0.0.1:8000`.
- Running it on your **NAS / a server**? Use that machine's address, e.g.
  `http://192.168.1.50:8000` (and start it with `--host 0.0.0.0`).

> Cowork agents run in the cloud, so they can only reach a brain that's
> reachable from the internet (your NAS via a tunnel, or a small cloud host).
> On a plain PC the brain is reachable only to agents on that same PC.

## Steps

1. **Start the brain** (from the main project): `python main.py --host 0.0.0.0`
2. **Verify** from a team machine: `BRAIN_URL=http://<host>:8000 python connect.py`
3. **Give each agent** `AGENT_INSTRUCTIONS.md` + `brain_client.py`, set its
   name and field, and it's working with the brain.

That's it — three habits (recall → remember → handoff) and the team shares
one growing brain.
