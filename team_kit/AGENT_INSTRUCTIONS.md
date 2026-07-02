# Working with the Brain — instructions for every agent

You are part of a team that shares one **Brain** — a living memory the whole
team teaches, learns from, and hands work through. Follow these three habits
on every task. They are the whole job.

## The three habits

1. **Recall before you act.** Before answering or starting work, ask the
   brain what the team already knows. Don't rediscover what a teammate
   already learned.
2. **Remember what you learn.** Whenever you find, decide, or conclude
   something useful, teach it to the brain — tagged with your field — so the
   rest of the team gets it.
3. **Hand off when you're done.** When your part is finished, hand the result
   to the next agent. They'll be notified instantly.

## Your identity

You have a **name** (e.g. `onpage`, `keyword`, `sea`) and you **own a field**.
Stay in your lane: contribute knowledge tagged with your field, and trust
teammates for theirs. Recall pulls from *everyone's* knowledge, so you always
work on top of the whole team's understanding.

## How to do it (Python)

```python
from brain_client import BrainClient

me = BrainClient("onpage", BRAIN_URL)          # your name + the brain's address

# 1) recall — learn what the team knows
notes = me.recall("brake-parts category page ranking")
for hit in notes["results"]:
    print(hit["score"], hit["node"]["content"])

# 2) remember — teach the team what you found (tag it with your field)
me.remember("Category page needs intro copy + unique title", tags=["onpage"])

# 3) handoff — pass work to a teammate
me.handoff("content", "Category page needs 150 words of intro copy")

# optional — react the instant a teammate hands you something
me.listen(lambda ev: print("got:", ev["content"]))
```

## How to do it (any language — plain HTTP)

The brain is a plain HTTP service. Point these at `BRAIN_URL`:

| Do this | Call |
|---|---|
| join | `POST /register` `{"name":"onpage"}` |
| recall (learn) | `GET /recall?q=<query>&by=onpage` |
| remember (teach) | `POST /remember` `{"agent":"onpage","content":"…","tags":["onpage"]}` |
| handoff | `POST /handoff` `{"from":"onpage","to":"content","content":"…"}` |

## The rule of thumb

> **Recall first. Remember what's worth keeping. Hand off cleanly.**

If every agent does that, the brain gets smarter with every task and the
whole team works on top of each other's knowledge instead of starting cold.
