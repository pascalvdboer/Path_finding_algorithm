"""Verify the brain works — storage, linking, recall, the professor,
steering, metrics, and the live HTTP API. Pure standard library.

    python tests/test_brain.py
"""

import os
import sys
import time
import threading
import urllib.request
import json

ROOT = os.path.join(os.path.dirname(__file__), "..")
sys.path.insert(0, ROOT)
sys.path.insert(0, os.path.join(ROOT, "client"))

from brain.store import Brain                     # noqa: E402
from knowledge.seo_corpus import CORPUS           # noqa: E402

_passed = 0
_failed = 0


def check(name, cond):
    global _passed, _failed
    if cond:
        _passed += 1
        print(f"  ✓ {name}")
    else:
        _failed += 1
        print(f"  ✗ {name}")


def test_store():
    print("store: memory, auto-linking, recall")
    b = Brain(":memory:")
    n1, _ = b.remember("scout", "The API rate limit is 60 requests per minute", ["api", "limits"])
    n2, e2 = b.remember("builder", "Retry with exponential backoff on HTTP 429", ["api", "limits"])
    check("second memory wired to the first", any(e["dst"] == n1["id"] for e in e2))
    hits = b.recall("rate limit backoff")
    check("recall returns something", len(hits) > 0)
    check("recall ranks the rate-limit memory top", hits[0]["node"]["id"] in (n1["id"], n2["id"]))


def test_professor():
    print("professor: teaches the best of a field")
    b = Brain(":memory:")
    for field, kind, text, tags in CORPUS:
        b.remember("feeder", text, list(tags) + [field, kind])
    tech = b.teach(field="technical", k=4)
    check("teaches something for technical", len(tech) > 0)
    check("technical lessons are about technical", any("technical" in l["tags"] for l in tech))
    sea = b.teach(field="sea", query="quality score cpc", k=3)
    check("quality-score fact tops the SEA lesson", "Quality Score" in sea[0]["content"])


def test_steering():
    print("steering: change an agent while it runs")
    b = Brain(":memory:")
    b.set_directive("*", {"pace": 1.0})
    b.set_directive("feeder", {"focus": ["technical"]})
    d = b.get_directive("feeder")
    check("global + agent directive merge", d.get("pace") == 1.0 and d.get("focus") == ["technical"])
    b.set_directive("feeder", {"paused": True})
    check("directive updates merge, not clobber", b.get_directive("feeder").get("focus") == ["technical"])


def test_metrics():
    print("metrics: collaboration index")
    b = Brain(":memory:")
    b.remember("a", "shared topic alpha beta", ["x"])
    b.remember("b", "shared topic alpha gamma", ["x"])
    m = b.metrics()
    check("collaboration index computed", 0.0 <= m["collaboration_index"] <= 1.0)
    check("minds listed", len(m["minds"]) == 2)


def test_http():
    print("http api: live end to end")
    from brain.server import BrainApp, make_handler
    from http.server import ThreadingHTTPServer
    app = BrainApp(":memory:")
    httpd = ThreadingHTTPServer(("127.0.0.1", 0), make_handler(app))
    port = httpd.server_address[1]
    threading.Thread(target=httpd.serve_forever, daemon=True).start()
    time.sleep(0.3)
    base = f"http://127.0.0.1:{port}"

    def post(path, obj):
        req = urllib.request.Request(base + path, data=json.dumps(obj).encode(),
                                     headers={"Content-Type": "application/json"})
        return json.load(urllib.request.urlopen(req))

    def get(path):
        return json.load(urllib.request.urlopen(base + path))

    post("/register", {"name": "onpage"})
    r = post("/remember", {"agent": "onpage", "content": "Title tags under 60 chars", "tags": ["onpage"]})
    check("remember returns a node", "node" in r)
    post("/remember", {"agent": "feeder", "content": "Canonical tags fix duplicates", "tags": ["technical", "fact"]})
    check("teach endpoint works", len(get("/teach?field=technical")["lessons"]) > 0)
    check("recall endpoint works", "results" in get("/recall?q=title&by=onpage"))
    post("/steer", {"agent": "feeder", "focus": ["technical"]})
    check("steer persists", get("/directive?agent=feeder").get("focus") == ["technical"])
    check("metrics endpoint works", "collaboration_index" in get("/metrics"))
    check("dashboard is served", b"<canvas" in urllib.request.urlopen(base + "/").read())
    httpd.shutdown()


def main():
    for t in (test_store, test_professor, test_steering, test_metrics, test_http):
        t()
    print(f"\n{_passed} passed, {_failed} failed")
    sys.exit(1 if _failed else 0)


if __name__ == "__main__":
    main()
