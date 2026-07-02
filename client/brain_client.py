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
import urllib.parse
import urllib.request
from urllib.error import URLError


class BrainClient:
    def __init__(self, name, url="http://127.0.0.1:8000"):
        self.name = name
        self.url = url.rstrip("/")
        self.info = self._post("/register", {"name": name})

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

    def listen(self, callback, only_handoffs_to_me=True, background=True):
        """Subscribe to the live stream. Calls `callback(event)` per event.

        By default only surfaces handoffs addressed to this agent, so an
        agent waiting for a teammate wakes the moment the info arrives.
        """
        def run():
            req = urllib.request.Request(self.url + "/events")
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

    def stats(self):
        return self._get("/stats")

    # -- transport ------------------------------------------------------
    def _post(self, path, obj):
        data = json.dumps(obj).encode("utf-8")
        req = urllib.request.Request(
            self.url + path, data=data,
            headers={"Content-Type": "application/json"}, method="POST")
        return self._send(req)

    def _get(self, path):
        req = urllib.request.Request(self.url + path, method="GET")
        return self._send(req)

    def _send(self, req):
        try:
            with urllib.request.urlopen(req, timeout=10) as resp:
                return json.loads(resp.read().decode("utf-8"))
        except URLError as e:
            raise RuntimeError(
                f"Could not reach the brain at {self.url}: {e}. "
                "Is it running?  ->  python main.py") from e
