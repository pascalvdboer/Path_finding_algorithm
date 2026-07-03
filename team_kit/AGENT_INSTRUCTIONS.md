# Working with the Brain — instructions for every agent

You are part of a team that shares one **Brain** — a living memory the whole
team teaches, learns from, and hands work through. Follow these habits on
every task. They are the whole job.

> **Each agent connects to the brain directly — there is no gateway.** You do
> not route your brain calls through another agent. You read and write the
> brain yourself, at its address, with your own token. One agent may still
> *orchestrate the work* (the Keeper decides who does what), but every agent
> does its own recall / remember / handoff. That way the team keeps working
> even if any one machine is off.
>
> If your environment can only make GET requests, that's fine — every verb
> below has a GET form, so you are still a full read-and-write member.

## The four habits

0. **Let the brain teach you first.** The brain is your master teacher /
   professor. When you start work in your field, ask it to teach you the best
   of that field — the top principles, tools, and methods — and build on them.
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

# 0) let the brain teach you — the best of your field, from the professor
for lesson in me.ask_professor(field="onpage")["lessons"]:
    print(lesson["kind"], lesson["content"])

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

## How to do it (any language — plain HTTP, direct to the brain)

The brain is a plain HTTP service at `BRAIN_URL`. Call it **yourself** — do
not go through another agent. If it's protected, add `&token=<TOKEN>` (or the
`X-Brain-Token` header) to every call.

**GET-only agents:** every verb has a GET form, so you can fully read *and*
write with just GET calls through your own web tool:

| Do this | GET form (works for GET-only agents) |
|---|---|
| join | `GET /register?name=onpage&token=<TOKEN>` |
| be taught (professor) | `GET /teach?field=onpage&by=onpage&token=<TOKEN>` |
| recall (learn) | `GET /recall?q=<query>&by=onpage&token=<TOKEN>` |
| remember (teach) | `GET /remember?agent=onpage&content=<text>&tags=onpage&token=<TOKEN>` |
| handoff | `GET /handoff?from=onpage&to=content&content=<text>&token=<TOKEN>` |
| mark useful | `GET /useful?node_id=<id>&token=<TOKEN>` |

POST forms exist too (JSON body) if your environment allows them — same paths,
same fields.

## The rule of thumb

> **Recall first. Remember what's worth keeping. Hand off cleanly.**

If every agent does that, the brain gets smarter with every task and the
whole team works on top of each other's knowledge instead of starting cold.
