"""The brain server: a dependency-free HTTP + Server-Sent-Events hub.

Agents talk to it over plain HTTP (remember / recall / handoff), and both
agents and the browser visualiser subscribe to a live event stream so
knowledge flows instantly. Runs on the Python standard library alone.
"""

import json
import os
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import urlparse, parse_qs

from .store import Brain
from .events import EventBus

WEB_DIR = os.path.join(os.path.dirname(__file__), "web")

# curriculum totals per field — the denominator for "% trained"
try:
    from knowledge.seo_corpus import FIELDS, by_field
    CURRICULUM_TOTALS = {f: len(by_field(f)) for f in FIELDS}
except Exception:
    CURRICULUM_TOTALS = {}


class BrainApp:
    """Holds the shared state passed to every request handler."""

    def __init__(self, db_path="brain.db"):
        self.brain = Brain(db_path)
        self.bus = EventBus()

    # Each write emits an event so the map lights up in real time.
    def remember(self, agent, content, tags):
        node, edges = self.brain.remember(agent, content, tags)
        if node.get("new", True):
            self.bus.publish({"type": "node_added", "node": node,
                              "color": self.brain.agent_color(agent)})
            for e in edges:
                self.bus.publish({"type": "edge_added", "edge": e})
            self.bus.publish({"type": "fire", "node": node["id"],
                              "targets": [e["dst"] for e in edges]})
        else:
            # a near-duplicate reinforced an existing memory — light it, don't add
            self.bus.publish({"type": "reinforce", "node": node["id"],
                              "color": self.brain.agent_color(agent)})
        return {"node": node, "linked": [e["dst"] for e in edges],
                "merged": not node.get("new", True)}

    def analyze(self, totals):
        """The Professor analyses what the team needs to perform far better:
        the biggest knowledge gaps and the weakest-covered fields, turned into
        concrete teaching priorities."""
        gaps = self.brain.top_gaps(k=8)
        coverage = self.brain.training(totals)
        weak = [c for c in coverage if c["pct"] < 100][:6]
        recs = []
        for g in gaps:
            recs.append({"priority": "gap", "need": g["topic"],
                         "why": f"agents asked {g['misses']}× and the brain barely had it",
                         "field": g.get("field")})
        for w in weak:
            recs.append({"priority": "coverage", "need": f"more {w['field']} knowledge",
                         "why": f"only {w['pct']}% of {w['field']} is trained",
                         "field": w["field"]})
        return {"gaps": gaps, "weakest_fields": weak, "recommendations": recs}

    def mark_useful(self, node_id):
        return self.brain.mark_useful(node_id)

    def recall(self, query, k, by=None):
        results = self.brain.recall(query, k)
        ids = [r["node"]["id"] for r in results]
        # whose knowledge did the asker just learn from?
        sources = [r["node"].get("agent") for r in results]
        self.bus.publish({"type": "recall", "query": query, "hits": ids,
                          "by": by, "sources": sources})
        return {"results": results}

    def handoff(self, src, dst, content, tags):
        node, edges = self.brain.handoff(src, dst, content, tags)
        self.bus.publish({"type": "node_added", "node": node,
                          "color": self.brain.agent_color(src)})
        for e in edges:
            self.bus.publish({"type": "edge_added", "edge": e})
        # a directed, highlighted pulse from teacher to student
        self.bus.publish({"type": "handoff", "from": src, "to": dst,
                          "node": node["id"], "content": content})
        return {"node": node, "to": dst}

    def professor(self, field, query, k, by=None):
        """The brain teaches an agent the best of its field, and lights the
        cortex where the teaching came from."""
        lessons = self.brain.teach(field, query, k)
        self.bus.publish({"type": "recall", "query": query or field or "teach me",
                          "by": by, "hits": [], "sources": ["feeder"]})
        return {"field": field, "lessons": lessons}

    def steer(self, agent, patch):
        """Change an agent's directive while it runs and push it live."""
        directive = self.brain.set_directive(agent, patch)
        self.bus.publish({"type": "steer", "agent": agent,
                          "directive": directive})
        return {"agent": agent, "directive": directive}


def make_handler(app):
    class Handler(BaseHTTPRequestHandler):
        protocol_version = "HTTP/1.1"

        # -- helpers ----------------------------------------------------
        def _json(self, obj, code=200):
            body = json.dumps(obj).encode("utf-8")
            self.send_response(code)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(body)))
            self.send_header("Access-Control-Allow-Origin", "*")
            self.end_headers()
            self.wfile.write(body)

        def _body(self):
            length = int(self.headers.get("Content-Length", 0) or 0)
            if not length:
                return {}
            raw = self.rfile.read(length)
            try:
                return json.loads(raw)
            except ValueError:
                return {}

        def log_message(self, *args):
            pass  # keep the console clean; the map is the log

        # -- routing ----------------------------------------------------
        def do_GET(self):
            u = urlparse(self.path)
            q = parse_qs(u.query)
            if u.path == "/" or u.path == "/index.html":
                return self._file("index.html", "text/html")
            if u.path == "/events":
                return self._sse()
            if u.path == "/state":
                return self._json(app.brain.snapshot())
            if u.path == "/stats":
                return self._json(app.brain.stats())
            if u.path == "/metrics":
                return self._json(app.brain.metrics())
            if u.path == "/training":
                return self._json({"fields": app.brain.training(CURRICULUM_TOTALS)})
            if u.path == "/gaps":
                return self._json({"gaps": app.brain.top_gaps(
                    int((q.get("k") or ["8"])[0]))})
            if u.path == "/analyze":
                return self._json(app.analyze(CURRICULUM_TOTALS))
            if u.path == "/teach":
                field = (q.get("field") or [None])[0]
                query = (q.get("q") or [None])[0]
                k = int((q.get("k") or ["6"])[0])
                by = (q.get("by") or [None])[0]
                return self._json(app.professor(field, query, k, by))
            if u.path == "/directive":
                who = (q.get("agent") or ["anon"])[0]
                return self._json(app.brain.get_directive(who))
            if u.path == "/directives":
                return self._json(app.brain.all_directives())
            if u.path == "/recall":
                query = (q.get("q") or [""])[0]
                k = int((q.get("k") or ["5"])[0])
                by = (q.get("by") or [None])[0]
                return self._json(app.recall(query, k, by))
            return self._json({"error": "not found"}, 404)

        def do_POST(self):
            u = urlparse(self.path)
            data = self._body()
            if u.path == "/register":
                return self._json(app.brain.register(data.get("name", "anon")))
            if u.path == "/remember":
                return self._json(app.remember(
                    data.get("agent", "anon"),
                    data.get("content", ""),
                    data.get("tags", []),
                ))
            if u.path == "/handoff":
                return self._json(app.handoff(
                    data.get("from", "anon"),
                    data.get("to", "anon"),
                    data.get("content", ""),
                    data.get("tags", []),
                ))
            if u.path == "/steer":
                return self._json(app.steer(
                    data.get("agent", "*"),
                    {k: v for k, v in data.items() if k != "agent"},
                ))
            if u.path == "/useful":
                return self._json(app.mark_useful(data.get("node_id")))
            if u.path == "/gap_filled":
                app.brain.mark_gap_filled(data.get("topic", ""))
                return self._json({"ok": True})
            if u.path == "/decay":
                app.brain.decay()
                return self._json({"ok": True})
            return self._json({"error": "not found"}, 404)

        # -- static files ----------------------------------------------
        def _file(self, name, ctype):
            path = os.path.join(WEB_DIR, name)
            try:
                with open(path, "rb") as fh:
                    body = fh.read()
            except OSError:
                return self._json({"error": "missing"}, 404)
            self.send_response(200)
            self.send_header("Content-Type", ctype)
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)

        # -- server-sent events ----------------------------------------
        def _sse(self):
            self.send_response(200)
            self.send_header("Content-Type", "text/event-stream")
            self.send_header("Cache-Control", "no-cache")
            self.send_header("Connection", "keep-alive")
            self.send_header("Access-Control-Allow-Origin", "*")
            self.end_headers()
            q = app.bus.subscribe()
            # prime with a full snapshot so a fresh browser draws the
            # whole brain immediately
            try:
                self._emit({"type": "snapshot", "state": app.brain.snapshot()})
                while True:
                    try:
                        event = q.get(timeout=15)
                        self._emit(event)
                    except Exception:
                        # heartbeat keeps proxies from closing the pipe
                        self.wfile.write(b": ping\n\n")
                        self.wfile.flush()
            except (BrokenPipeError, ConnectionResetError):
                pass
            finally:
                app.bus.unsubscribe(q)

        def _emit(self, event):
            payload = "data: %s\n\n" % json.dumps(event)
            self.wfile.write(payload.encode("utf-8"))
            self.wfile.flush()

    return Handler


def serve(host="127.0.0.1", port=8000, db_path="brain.db"):
    app = BrainApp(db_path)
    httpd = ThreadingHTTPServer((host, port), make_handler(app))
    print(f"\n  🧠  The Agent Brain is awake")
    print(f"      visualiser : http://{host}:{port}")
    print(f"      db         : {os.path.abspath(db_path)}")
    print(f"      stats      : {app.brain.stats()}\n")
    try:
        httpd.serve_forever()
    except KeyboardInterrupt:
        print("\n  🧠  Brain resting. Memories saved.\n")
        httpd.shutdown()
