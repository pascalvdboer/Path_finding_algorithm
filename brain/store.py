"""The cortex: a persistent knowledge graph backed by SQLite.

Nodes are memories/concepts (neurons). Edges are synapses. Agents are the
minds that connect in. On top of raw storage the brain now:

  * relates memories by *meaning* (IDF-weighted cosine over expanded tokens),
  * keeps itself clean — the **Filer**: near-duplicates merge and strengthen
    instead of piling up; strength decays so stale knowledge fades,
  * learns what's valuable — memories that get recalled/marked useful grow
    stronger and rank higher,
  * notices what it *lacks* — weak recalls log **knowledge gaps** the Feeder
    then fills on demand.

Everything is persisted, so the brain survives restarts.
"""

import json
import math
import sqlite3
import threading
import time
import hashlib

from . import linking

GAP_THRESHOLD = 0.16      # below this, a query counts as an unmet need
DEDUP_THRESHOLD = 0.9     # at/above this, a new memory merges into an existing one


def _now():
    return time.time()


def _color_for(name):
    h = int(hashlib.sha1(name.encode("utf-8")).hexdigest(), 16)
    return f"hsl({h % 360}, 85%, 62%)"


class Brain:
    def __init__(self, path="brain.db"):
        self._lock = threading.RLock()
        self._db = sqlite3.connect(path, check_same_thread=False)
        self._db.row_factory = sqlite3.Row
        self._init_schema()
        self._vec = {}          # node_id -> expanded token vector (meaning)
        self._df = {}           # term -> document frequency (for IDF)
        self._n = 0             # number of documents
        self._warm_cache()

    # -- schema ---------------------------------------------------------
    def _init_schema(self):
        with self._lock:
            self._db.executescript(
                """
                CREATE TABLE IF NOT EXISTS agents (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT UNIQUE NOT NULL, color TEXT NOT NULL,
                    joined REAL NOT NULL, seen REAL NOT NULL
                );
                CREATE TABLE IF NOT EXISTS nodes (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    agent TEXT, content TEXT NOT NULL, tags TEXT NOT NULL,
                    kind TEXT NOT NULL DEFAULT 'memory', created REAL NOT NULL,
                    strength REAL NOT NULL DEFAULT 1.0, uses INTEGER NOT NULL DEFAULT 0
                );
                CREATE TABLE IF NOT EXISTS edges (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    src INTEGER NOT NULL, dst INTEGER NOT NULL,
                    weight REAL NOT NULL DEFAULT 1.0, kind TEXT NOT NULL DEFAULT 'assoc',
                    created REAL NOT NULL
                );
                CREATE TABLE IF NOT EXISTS directives (
                    agent TEXT PRIMARY KEY, data TEXT NOT NULL, updated REAL NOT NULL
                );
                CREATE TABLE IF NOT EXISTS gaps (
                    topic TEXT PRIMARY KEY, field TEXT, misses INTEGER NOT NULL DEFAULT 1,
                    best REAL NOT NULL DEFAULT 0, updated REAL NOT NULL, filled INTEGER NOT NULL DEFAULT 0
                );
                CREATE TABLE IF NOT EXISTS fields (
                    name TEXT PRIMARY KEY, added REAL NOT NULL
                );
                """
            )
            # migrate older DBs that predate strength/uses
            for col, ddl in (("strength", "ALTER TABLE nodes ADD COLUMN strength REAL NOT NULL DEFAULT 1.0"),
                             ("uses", "ALTER TABLE nodes ADD COLUMN uses INTEGER NOT NULL DEFAULT 0")):
                try:
                    self._db.execute(ddl)
                except sqlite3.OperationalError:
                    pass
            self._db.commit()

    def _warm_cache(self):
        with self._lock:
            for row in self._db.execute("SELECT id, content, tags FROM nodes"):
                vec = linking.vector(row["content"], json.loads(row["tags"]))
                self._vec[row["id"]] = vec
                self._n += 1
                for t in vec:
                    self._df[t] = self._df.get(t, 0) + 1

    # -- meaning --------------------------------------------------------
    def idf(self, term):
        return math.log((1 + self._n) / (1 + self._df.get(term, 0))) + 1.0

    def _sim(self, a, b):
        return linking.cosine(a, b, self.idf)

    def _index(self, node_id, vec):
        self._vec[node_id] = vec
        self._n += 1
        for t in vec:
            self._df[t] = self._df.get(t, 0) + 1

    # -- agents ---------------------------------------------------------
    def register(self, name):
        with self._lock:
            row = self._db.execute("SELECT * FROM agents WHERE name = ?", (name,)).fetchone()
            if row:
                self._db.execute("UPDATE agents SET seen = ? WHERE id = ?", (_now(), row["id"]))
                self._db.commit()
                return dict(row)
            color, now = _color_for(name), _now()
            cur = self._db.execute(
                "INSERT INTO agents (name, color, joined, seen) VALUES (?,?,?,?)",
                (name, color, now, now))
            self._db.commit()
            return {"id": cur.lastrowid, "name": name, "color": color, "joined": now, "seen": now}

    def agent_color(self, name):
        with self._lock:
            row = self._db.execute("SELECT color FROM agents WHERE name = ?", (name,)).fetchone()
            return row["color"] if row else _color_for(name or "anon")

    def _node_dict(self, row):
        n = dict(row)
        n["tags"] = json.loads(n["tags"])
        return n

    # -- writing knowledge (teach) --------------------------------------
    def remember(self, agent, content, tags=None, kind="memory"):
        """Store a memory and auto-wire it. The Filer merges near-duplicates:
        an almost-identical memory reinforces the existing one instead of
        cluttering the brain. Returns (node_dict, [new_edges]); node['new']
        is False when it merged into an existing memory."""
        tags = tags or []
        with self._lock:
            now = _now()
            vec = linking.vector(content, tags)

            # Filer: dedup — reinforce the closest near-identical memory
            best_id, best_s = None, 0.0
            for nid, v in self._vec.items():
                s = self._sim(vec, v)
                if s > best_s:
                    best_s, best_id = s, nid
            if best_id is not None and best_s >= DEDUP_THRESHOLD:
                self._db.execute(
                    "UPDATE nodes SET strength = strength + 0.5, uses = uses + 1 WHERE id = ?",
                    (best_id,))
                self._db.commit()
                row = self._db.execute("SELECT * FROM nodes WHERE id = ?", (best_id,)).fetchone()
                node = self._node_dict(row)
                node["new"] = False
                return node, []

            cur = self._db.execute(
                "INSERT INTO nodes (agent, content, tags, kind, created) VALUES (?,?,?,?,?)",
                (agent, content, json.dumps(tags), kind, now))
            node_id = cur.lastrowid
            existing = list(self._vec.items())
            self._index(node_id, vec)
            links = linking.strongest_links(vec, existing, self.idf, threshold=0.15)

            new_edges = []
            for other_id, weight in links:
                ec = self._db.execute(
                    "INSERT INTO edges (src, dst, weight, kind, created) VALUES (?,?,?,?,?)",
                    (node_id, other_id, weight, "assoc", now))
                new_edges.append({"id": ec.lastrowid, "src": node_id, "dst": other_id,
                                  "weight": weight, "kind": "assoc"})
            self._db.commit()
            node = {"id": node_id, "agent": agent, "content": content, "tags": tags,
                    "kind": kind, "created": now, "strength": 1.0, "uses": 0, "new": True}
            return node, new_edges

    def _reinforce(self, node_ids, amount=0.3):
        if not node_ids:
            return
        q = ",".join("?" * len(node_ids))
        self._db.execute(
            f"UPDATE nodes SET strength = strength + {amount}, uses = uses + 1 WHERE id IN ({q})",
            tuple(node_ids))
        self._db.commit()

    def mark_useful(self, node_id):
        """An agent says this memory helped — the brain values it more."""
        with self._lock:
            self._reinforce([node_id], amount=1.0)
            return {"id": node_id, "reinforced": True}

    # -- reading knowledge (learn) --------------------------------------
    def recall(self, query, k=5, agent=None):
        """Top-k memories by meaning. Recalled memories grow stronger; a weak
        best match logs a knowledge gap for the Feeder to fill."""
        qvec = linking.vector(query)
        with self._lock:
            scored = []
            for nid, v in self._vec.items():
                s = self._sim(qvec, v)
                if s > 0:
                    scored.append((nid, s))
            scored.sort(key=lambda x: x[1], reverse=True)
            top = scored[:k]
            best = top[0][1] if top else 0.0
            results = []
            for nid, s in top:
                row = self._db.execute("SELECT * FROM nodes WHERE id = ?", (nid,)).fetchone()
                if row:
                    results.append({"node": self._node_dict(row), "score": round(s, 4)})
            self._reinforce([r["node"]["id"] for r in results])
            if query.strip() and best < GAP_THRESHOLD:
                self._log_gap(query, None, best)
            return results

    # -- the Professor: teach the best of a field -----------------------
    def teach(self, field=None, query=None, k=6):
        """Teach an agent the best of its field — authoritative, curated
        knowledge, ranked by meaning *and* proven value (strength)."""
        qvec = linking.vector(query or field or "")
        KINDS = {"fact", "tool", "training"}
        with self._lock:
            scored = []
            for r in self._db.execute("SELECT * FROM nodes"):
                tags = json.loads(r["tags"])
                tagset = set(tags)
                if r["agent"] != "feeder" and not (tagset & KINDS):
                    continue
                vec = self._vec.get(r["id"]) or linking.vector(r["content"], tags)
                score = self._sim(qvec, vec) if qvec else 0.0
                if field and field in tagset:
                    score += 0.6
                score += 0.15 if "fact" in tagset else 0.08 if "training" in tagset else 0.0
                score += 0.08 * math.log(1 + (r["strength"] or 1.0))   # proven value
                if score <= 0 and not (field or query):
                    score = 0.01
                if score > 0:
                    kind = next((t for t in tags if t in KINDS), "fact")
                    scored.append((score, r["id"], {"content": r["content"], "tags": tags,
                                                    "kind": kind, "field": field}))
            scored.sort(key=lambda x: x[0], reverse=True)
            top = scored[:k]
            self._reinforce([nid for _s, nid, _i in top], amount=0.2)
            if (query or "").strip() and (not top or top[0][0] < GAP_THRESHOLD):
                self._log_gap(query or field or "", field, top[0][0] if top else 0.0)
            return [item for _s, _nid, item in top]

    # -- knowledge gaps (what the team needs but the brain lacks) --------
    def _log_gap(self, topic, field, best):
        topic = (topic or "").strip().lower()[:120]
        if not topic:
            return
        self._db.execute(
            "INSERT INTO gaps (topic, field, misses, best, updated, filled) VALUES (?,?,1,?,?,0)"
            " ON CONFLICT(topic) DO UPDATE SET misses = misses + 1, best = ?,"
            " updated = ?, filled = 0",
            (topic, field, best, _now(), best, _now()))
        self._db.commit()

    def top_gaps(self, k=8, include_filled=False):
        with self._lock:
            q = "SELECT * FROM gaps"
            if not include_filled:
                q += " WHERE filled = 0"
            q += " ORDER BY misses DESC, updated DESC LIMIT ?"
            return [dict(r) for r in self._db.execute(q, (k,))]

    def mark_gap_filled(self, topic):
        with self._lock:
            self._db.execute("UPDATE gaps SET filled = 1 WHERE topic = ?",
                             ((topic or "").strip().lower()[:120],))
            self._db.commit()

    # -- the Filer: clean up duplicates already in the brain ------------
    def dedupe(self):
        """Merge exact-duplicate memories that piled up (e.g. an old Feeder
        loop re-teaching the same text). Keeps the earliest copy, sums its
        strength/uses, rewires synapses onto it, and drops the rest."""
        with self._lock:
            seen = {}
            removed = 0
            for r in self._db.execute("SELECT id, content FROM nodes ORDER BY id"):
                c = r["content"]
                if c in seen:
                    keep, dup = seen[c], r["id"]
                    self._db.execute(
                        "UPDATE nodes SET strength = strength + "
                        "(SELECT strength FROM nodes WHERE id=?), uses = uses + "
                        "(SELECT uses FROM nodes WHERE id=?) WHERE id=?",
                        (dup, dup, keep))
                    self._db.execute("UPDATE edges SET src=? WHERE src=?", (keep, dup))
                    self._db.execute("UPDATE edges SET dst=? WHERE dst=?", (keep, dup))
                    self._db.execute("DELETE FROM edges WHERE src=dst")
                    self._db.execute("DELETE FROM nodes WHERE id=?", (dup,))
                    removed += 1
                else:
                    seen[c] = r["id"]
            # collapse duplicate synapses (same src-dst)
            self._db.execute(
                "DELETE FROM edges WHERE id NOT IN "
                "(SELECT MIN(id) FROM edges GROUP BY src, dst)")
            self._db.commit()
            # rebuild the meaning caches from the cleaned store
            self._vec, self._df, self._n = {}, {}, 0
            self._warm_cache()
            return {"removed": removed, "neurons": self.stats()["neurons"]}

    # -- the Filer's upkeep: let stale knowledge fade -------------------
    def decay(self, factor=0.995, floor=0.2):
        """Gently reduce every memory's strength so unused knowledge fades and
        frequently-used knowledge stays prominent. Called periodically."""
        with self._lock:
            self._db.execute("UPDATE nodes SET strength = MAX(?, strength * ?)", (floor, factor))
            self._db.commit()

    # -- direct handoff -------------------------------------------------
    def handoff(self, src_agent, dst_agent, content, tags=None):
        node, edges = self.remember(src_agent, content, tags, kind="handoff")
        return node, edges

    def link(self, src, dst, weight=1.0, kind="taught"):
        with self._lock:
            cur = self._db.execute(
                "INSERT INTO edges (src, dst, weight, kind, created) VALUES (?,?,?,?,?)",
                (src, dst, weight, kind, _now()))
            self._db.commit()
            return {"id": cur.lastrowid, "src": src, "dst": dst, "weight": weight, "kind": kind}

    # -- live steering --------------------------------------------------
    def set_directive(self, agent, patch):
        with self._lock:
            row = self._db.execute("SELECT data FROM directives WHERE agent = ?", (agent,)).fetchone()
            current = json.loads(row["data"]) if row else {}
            current.update(patch or {})
            self._db.execute(
                "INSERT INTO directives (agent, data, updated) VALUES (?,?,?)"
                " ON CONFLICT(agent) DO UPDATE SET data=excluded.data, updated=excluded.updated",
                (agent, json.dumps(current), _now()))
            self._db.commit()
            return current

    def get_directive(self, agent):
        with self._lock:
            merged = {}
            for key in ("*", agent):
                row = self._db.execute("SELECT data FROM directives WHERE agent = ?", (key,)).fetchone()
                if row:
                    merged.update(json.loads(row["data"]))
            return merged

    def all_directives(self):
        with self._lock:
            return {r["agent"]: json.loads(r["data"])
                    for r in self._db.execute("SELECT agent, data FROM directives")}

    # -- fields / skill areas (extensible) ------------------------------
    def add_field(self, name):
        name = (name or "").strip().lower()
        if not name:
            return {"ok": False}
        with self._lock:
            self._db.execute(
                "INSERT INTO fields (name, added) VALUES (?,?) ON CONFLICT(name) DO NOTHING",
                (name, _now()))
            self._db.commit()
            return {"ok": True, "field": name}

    def added_fields(self):
        with self._lock:
            return [r["name"] for r in self._db.execute("SELECT name FROM fields")]

    # -- knowledge depth per field --------------------------------------
    def training(self, totals):
        """How much the brain actually *knows* per field — real depth, not
        coverage of a fixed seed list. `known` counts distinct authoritative
        memories in the field; `all` counts everything tagged with it. The
        dashboard shows these as bars relative to the richest field, so the
        numbers keep growing as the team and Feeder add knowledge."""
        fields = list(totals)
        KINDS = {"fact", "tool", "training"}
        known = {f: set() for f in fields}
        allc = {f: 0 for f in fields}
        with self._lock:
            for r in self._db.execute("SELECT agent, content, tags FROM nodes"):
                tagset = set(json.loads(r["tags"]))
                for f in fields:
                    if f in tagset:
                        allc[f] += 1
                        if r["agent"] == "feeder" or (tagset & KINDS):
                            known[f].add(r["content"])
        out = [{"field": f, "known": len(known[f]), "all": allc[f]} for f in fields]
        out.sort(key=lambda x: (x["known"], x["all"]), reverse=True)
        return out

    # -- collaboration analytics ----------------------------------------
    def metrics(self):
        with self._lock:
            author, per_agent = {}, {}
            for r in self._db.execute("SELECT id, agent FROM nodes"):
                author[r["id"]] = r["agent"]
                per_agent.setdefault(r["agent"], {"agent": r["agent"], "neurons": 0,
                                                  "reach": 0, "handoffs": 0})
                per_agent[r["agent"]]["neurons"] += 1
            cross = total = 0
            pairs = {}
            for r in self._db.execute("SELECT src, dst, kind FROM edges"):
                sa, da = author.get(r["src"]), author.get(r["dst"])
                if sa is None or da is None:
                    continue
                total += 1
                if sa != da:
                    cross += 1
                    if sa in per_agent:
                        per_agent[sa]["reach"] += 1
                    if da in per_agent:
                        per_agent[da]["reach"] += 1
                    key = " ↔ ".join(sorted([sa, da]))
                    pairs[key] = pairs.get(key, 0) + 1
                    if r["kind"] == "handoff" and sa in per_agent:
                        per_agent[sa]["handoffs"] += 1
            index = round(cross / total, 3) if total else 0.0
            top_pairs = sorted(pairs.items(), key=lambda x: x[1], reverse=True)
            minds = sorted(per_agent.values(), key=lambda m: (m["reach"], m["neurons"]), reverse=True)
            return {"collaboration_index": index, "cross_links": cross, "total_links": total,
                    "minds": minds, "pairs": [{"pair": k, "links": v} for k, v in top_pairs]}

    # -- snapshot & stats -----------------------------------------------
    def snapshot(self):
        with self._lock:
            agents = [dict(r) for r in self._db.execute("SELECT * FROM agents")]
            nodes = [self._node_dict(r) for r in self._db.execute("SELECT * FROM nodes")]
            edges = [dict(r) for r in self._db.execute("SELECT * FROM edges")]
            return {"agents": agents, "nodes": nodes, "edges": edges}

    def stats(self):
        with self._lock:
            a = self._db.execute("SELECT COUNT(*) c FROM agents").fetchone()["c"]
            n = self._db.execute("SELECT COUNT(*) c FROM nodes").fetchone()["c"]
            e = self._db.execute("SELECT COUNT(*) c FROM edges").fetchone()["c"]
            g = self._db.execute("SELECT COUNT(*) c FROM gaps WHERE filled = 0").fetchone()["c"]
            return {"agents": a, "neurons": n, "synapses": e, "gaps": g}
