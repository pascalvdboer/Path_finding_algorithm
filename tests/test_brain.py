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


def test_semantic():
    print("meaning: recall understands phrasing, not just words")
    b = Brain(":memory:")
    for field, kind, text, tags in CORPUS:
        b.remember("feeder", text, list(tags) + [field, kind])
    # 'pages competing' never appears; the concept is 'cannibalization'
    hits = b.recall("pages competing with each other for the same term")
    check("finds cannibalization by meaning", hits and "cannibali" in hits[0]["node"]["content"].lower())


def test_filer_dedup():
    print("filer: near-duplicates merge instead of piling up")
    b = Brain(":memory:")
    for _ in range(3):
        for field, kind, text, tags in CORPUS:
            b.remember("feeder", text, list(tags) + [field, kind])
    check("feeding 3x did not triple the neurons", b.stats()["neurons"] <= len(CORPUS) + 2)


def test_gaps_and_analyze():
    print("gaps & analysis: the brain notices what it lacks")
    b = Brain(":memory:")
    for field, kind, text, tags in CORPUS:
        b.remember("feeder", text, list(tags) + [field, kind])
    b.recall("how to run tiktok ads for gen z audiences")
    b.recall("how to run tiktok ads for gen z audiences")
    gaps = b.top_gaps()
    check("an unmet need is logged as a gap", any("tiktok" in g["topic"] for g in gaps))
    check("gap counts repeated misses", any(g["misses"] >= 2 for g in gaps))


def test_useful():
    print("value: marking a memory useful strengthens it")
    b = Brain(":memory:")
    n, _ = b.remember("onpage", "Title tags under 60 characters", ["onpage"])
    b.mark_useful(n["id"])
    row = b._db.execute("SELECT strength FROM nodes WHERE id = ?", (n["id"],)).fetchone()
    check("strength rose after 'useful'", row["strength"] > 1.0)


def test_dedupe():
    print("cleanup: exact duplicates merge")
    b = Brain(":memory:")
    for _ in range(4):
        b.remember("feeder", "Ahrefs Backlink audit finds toxic links", ["linkbuilding", "tool"])
    before = b.stats()["neurons"]
    res = b.dedupe()
    check("duplicates were removed", res["removed"] == before - 1 and b.stats()["neurons"] == 1)


def test_dynamic_fields():
    print("fields: expand by agent, by director, by demand")
    b = Brain(":memory:")
    b.add_field("options-trading")
    check("director can add a field", "options-trading" in b.added_fields())
    b.remember("optionsbot", "Sell covered calls above resistance", ["options-trading", "fact"])
    depth = b.training(["options-trading", "seo"])
    opt = [d for d in depth if d["field"] == "options-trading"][0]
    check("knowledge counts toward the new field", opt["known"] >= 1)


def test_token_and_getwrite():
    print("security + GET-write: token gate and GET-only writing")
    from brain.server import BrainApp, make_handler
    from http.server import ThreadingHTTPServer
    os.environ["BRAIN_TOKEN"] = "s3cret"
    try:
        app = BrainApp(":memory:")
        httpd = ThreadingHTTPServer(("127.0.0.1", 0), make_handler(app))
        port = httpd.server_address[1]
        threading.Thread(target=httpd.serve_forever, daemon=True).start()
        time.sleep(0.3)
        base = f"http://127.0.0.1:{port}"

        def code(path):
            try:
                urllib.request.urlopen(base + path); return 200
            except urllib.error.HTTPError as e:
                return e.code

        check("request without token is blocked", code("/stats") == 401)
        check("request with token is allowed", code("/stats?token=s3cret") == 200)
        # GET-write (for GET-only agents), with token
        urllib.request.urlopen(base + "/register?name=trader&token=s3cret")
        urllib.request.urlopen(base + "/remember?agent=trader&content=SPY%20broke%20support&tags=macro&token=s3cret")
        got = json.load(urllib.request.urlopen(base + "/recall?q=SPY%20support&token=s3cret"))
        check("GET-write stored a memory", got["results"] and "SPY" in got["results"][0]["node"]["content"])
        httpd.shutdown()
    finally:
        del os.environ["BRAIN_TOKEN"]


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
    for _ in range(2):
        get("/recall?q=obscure%20unmet%20topic%20xyz&by=onpage")
    check("gaps endpoint works", "gaps" in get("/gaps"))
    check("analyze endpoint works", "recommendations" in get("/analyze"))
    nid = post("/remember", {"agent": "onpage", "content": "H1 once per page", "tags": ["onpage"]})["node"]["id"]
    check("useful endpoint works", post("/useful", {"node_id": nid}).get("reinforced"))
    check("dashboard is served", b"<canvas" in urllib.request.urlopen(base + "/").read())
    httpd.shutdown()


def main():
    for t in (test_store, test_professor, test_semantic, test_filer_dedup,
              test_gaps_and_analyze, test_useful, test_dedupe, test_dynamic_fields,
              test_token_and_getwrite, test_steering, test_metrics, test_http):
        t()
    print(f"\n{_passed} passed, {_failed} failed")
    sys.exit(1 if _failed else 0)


if __name__ == "__main__":
    main()
