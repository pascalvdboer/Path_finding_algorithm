"""The link agents plug into the brain with.

Drop this into any agent (or any Python process) and it can teach the
brain, learn from it, hand knowledge to a teammate, and listen for
handoffs pushed to it in real time. Standard-library only.

    from brain_client import BrainClient

    me = BrainClient("scout", "http://127.0.0.1:8000")
    me.remember("The API rate limit is 60 req/min", tags=["api", "limits"])
    hits = me.recall("rate limit")            # learn
    me.handoff("planner", "Use batching to stay under the limit")
    me.listen(lambda ev: print("got:", ev))   # fast handoffs, pushed
"""

import json
import threading
import os
import urllib.parse
import urllib.request
from urllib.error import URLError


class BrainClient:
    def __init__(self, name, url="http://127.0.0.1:8000", token=None):
        self.name = name
        self.url = url.rstrip("/")
        # shared secret, if the brain requires one (arg or BRAIN_TOKEN env)
        self.token = token or os.environ.get("BRAIN_TOKEN") or None
        self.info = self._post("/register", {"name": name})
        # a live, always-current view of how this agent is being steered
        self.directive = self._get(f"/directive?agent={urllib.parse.quote(name)}")

    # -- core verbs -----------------------------------------------------
    def remember(self, content, tags=None):
        """Teach the brain something. Auto-wires to related memories."""
        return self._post("/remember", {
            "agent": self.name, "content": content, "tags": tags or []})

    def recall(self, query, k=5):
        """Learn: pull the memories most related to a query."""
        q = urllib.parse.quote(query)
        return self._get(f"/recall?q={q}&k={k}&by={urllib.parse.quote(self.name)}")

    def handoff(self, to, content, tags=None):
        """Hand knowledge directly to another agent, pushed instantly."""
        return self._post("/handoff", {
            "from": self.name, "to": to,
            "content": content, "tags": tags or []})

    def ask_professor(self, topic=None, field=None, k=6):
        """Ask the brain to teach you the best of a field/topic.

        The brain is the master teacher: this returns the most valuable
        curated knowledge, tools and trainings for your craft, ranked.
        """
        parts = [f"k={k}", f"by={urllib.parse.quote(self.name)}"]
        if field:
            parts.append(f"field={urllib.parse.quote(field)}")
        if topic:
            parts.append(f"q={urllib.parse.quote(topic)}")
        return self._get("/teach?" + "&".join(parts))

    def mark_useful(self, node_id):
        """Tell the brain a memory actually helped — it will value it more,
        so proven knowledge rises to the top over time."""
        return self._post("/useful", {"node_id": node_id})

    def analyze(self):
        """The Professor's needs analysis: the biggest knowledge gaps and the
        weakest-covered fields, as concrete teaching priorities."""
        return self._get("/analyze")

    def gaps(self, k=8):
        """What the team keeps needing but the brain lacks."""
        return self._get(f"/gaps?k={k}")

    def listen(self, callback, only_handoffs_to_me=True, background=True):
        """Subscribe to the live stream. Calls `callback(event)` per event.

        By default only surfaces handoffs addressed to this agent, so an
        agent waiting for a teammate wakes the moment the info arrives.
        """
        def run():
            req = urllib.request.Request(self.url + "/events", headers=self._headers())
            with urllib.request.urlopen(req) as resp:
                for raw in resp:
                    line = raw.decode("utf-8").strip()
                    if not line.startswith("data:"):
                        continue
                    try:
                        ev = json.loads(line[5:].strip())
                    except ValueError:
                        continue
                    if only_handoffs_to_me:
                        if ev.get("type") == "handoff" and ev.get("to") == self.name:
                            callback(ev)
                    else:
                        callback(ev)

        if background:
            t = threading.Thread(target=run, daemon=True)
            t.start()
            return t
        run()

    # -- steering (change agents while they run) ------------------------
    def steer(self, agent, **patch):
        """Steer another agent (or '*' for all) live: focus, pace, pause,
        instruction — any keys you like. Pushed instantly to that agent."""
        return self._post("/steer", {"agent": agent, **patch})

    def refresh_directive(self):
        """Pull this agent's current directive on demand."""
        self.directive = self._get(
            f"/directive?agent={urllib.parse.quote(self.name)}")
        return self.directive

    def follow_steering(self, on_change=None):
        """Keep self.directive live: subscribe to steer events aimed at this
        agent (or '*') and update in the background. Optional on_change(d)."""
        def handle(ev):
            if ev.get("type") == "steer" and ev.get("agent") in (self.name, "*"):
                self.refresh_directive()
                if on_change:
                    on_change(self.directive)
        return self.listen(handle, only_handoffs_to_me=False)

    def add_field(self, name):
        """Add a new field / skill area to the brain so the team can start
        building knowledge in it (the director expanding what the team does)."""
        return self._get(f"/field?name={urllib.parse.quote(name)}")

    def stats(self):
        return self._get("/stats")

    # -- transport ------------------------------------------------------
    def _headers(self, base=None):
        h = dict(base or {})
        if self.token:
            h["X-Brain-Token"] = self.token
        return h

    def _post(self, path, obj):
        data = json.dumps(obj).encode("utf-8")
        req = urllib.request.Request(
            self.url + path, data=data,
            headers=self._headers({"Content-Type": "application/json"}), method="POST")
        return self._send(req)

    def _get(self, path):
        req = urllib.request.Request(self.url + path, headers=self._headers(), method="GET")
        return self._send(req)

    def _send(self, req):
        try:
            with urllib.request.urlopen(req, timeout=10) as resp:
                return json.loads(resp.read().decode("utf-8"))
        except URLError as e:
            raise RuntimeError(
                f"Could not reach the brain at {self.url}: {e}. "
                "Is it running?  ->  python main.py") from e
