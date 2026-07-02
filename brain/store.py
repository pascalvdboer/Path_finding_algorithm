"""The cortex: a persistent knowledge graph backed by SQLite.

Nodes are memories/concepts (neurons). Edges are synapses. Agents are
the minds that connect in. Everything is stored so the brain survives
restarts — a real place of storage, not a demo buffer.
"""

import json
import sqlite3
import threading
import time
import hashlib

from . import linking


def _now():
    return time.time()


def _color_for(name):
    """Deterministic bright colour per agent so the map stays legible."""
    h = int(hashlib.sha1(name.encode("utf-8")).hexdigest(), 16)
    hue = h % 360
    return f"hsl({hue}, 85%, 62%)"


class Brain:
    def __init__(self, path="brain.db"):
        self._lock = threading.RLock()
        self._db = sqlite3.connect(path, check_same_thread=False)
        self._db.row_factory = sqlite3.Row
        self._init_schema()
        # keyword cache: node_id -> set(keywords), for fast auto-linking
        self._kw = {}
        self._warm_cache()

    # -- schema ---------------------------------------------------------
    def _init_schema(self):
        with self._lock:
            self._db.executescript(
                """
                CREATE TABLE IF NOT EXISTS agents (
                    id      INTEGER PRIMARY KEY AUTOINCREMENT,
                    name    TEXT UNIQUE NOT NULL,
                    color   TEXT NOT NULL,
                    joined  REAL NOT NULL,
                    seen    REAL NOT NULL
                );
                CREATE TABLE IF NOT EXISTS nodes (
                    id       INTEGER PRIMARY KEY AUTOINCREMENT,
                    agent    TEXT,
                    content  TEXT NOT NULL,
                    tags     TEXT NOT NULL,
                    kind     TEXT NOT NULL DEFAULT 'memory',
                    created  REAL NOT NULL
                );
                CREATE TABLE IF NOT EXISTS edges (
                    id       INTEGER PRIMARY KEY AUTOINCREMENT,
                    src      INTEGER NOT NULL,
                    dst      INTEGER NOT NULL,
                    weight   REAL NOT NULL DEFAULT 1.0,
                    kind     TEXT NOT NULL DEFAULT 'assoc',
                    created  REAL NOT NULL
                );
                CREATE TABLE IF NOT EXISTS directives (
                    agent    TEXT PRIMARY KEY,
                    data     TEXT NOT NULL,
                    updated  REAL NOT NULL
                );
                """
            )
            self._db.commit()

    def _warm_cache(self):
        with self._lock:
            for row in self._db.execute("SELECT id, content, tags FROM nodes"):
                tags = json.loads(row["tags"])
                self._kw[row["id"]] = linking.keywords(row["content"], tags)

    # -- agents ---------------------------------------------------------
    def register(self, name):
        with self._lock:
            row = self._db.execute(
                "SELECT * FROM agents WHERE name = ?", (name,)
            ).fetchone()
            if row:
                self._db.execute(
                    "UPDATE agents SET seen = ? WHERE id = ?", (_now(), row["id"])
                )
                self._db.commit()
                return dict(row)
            color = _color_for(name)
            now = _now()
            cur = self._db.execute(
                "INSERT INTO agents (name, color, joined, seen) VALUES (?,?,?,?)",
                (name, color, now, now),
            )
            self._db.commit()
            return {
                "id": cur.lastrowid,
                "name": name,
                "color": color,
                "joined": now,
                "seen": now,
            }

    def agent_color(self, name):
        with self._lock:
            row = self._db.execute(
                "SELECT color FROM agents WHERE name = ?", (name,)
            ).fetchone()
            return row["color"] if row else _color_for(name or "anon")

    # -- writing knowledge (teach) --------------------------------------
    def remember(self, agent, content, tags=None, kind="memory"):
        """Store a memory and auto-wire it to related memories.

        Returns (node_dict, [new_edge_dicts]).
        """
        tags = tags or []
        with self._lock:
            now = _now()
            cur = self._db.execute(
                "INSERT INTO nodes (agent, content, tags, kind, created)"
                " VALUES (?,?,?,?,?)",
                (agent, content, json.dumps(tags), kind, now),
            )
            node_id = cur.lastrowid
            kw = linking.keywords(content, tags)
            # auto-link against everything already known
            existing = [(nid, k) for nid, k in self._kw.items()]
            self._kw[node_id] = kw
            links = linking.strongest_links(kw, existing)

            new_edges = []
            for other_id, weight in links:
                ec = self._db.execute(
                    "INSERT INTO edges (src, dst, weight, kind, created)"
                    " VALUES (?,?,?,?,?)",
                    (node_id, other_id, weight, "assoc", now),
                )
                new_edges.append(
                    {
                        "id": ec.lastrowid,
                        "src": node_id,
                        "dst": other_id,
                        "weight": weight,
                        "kind": "assoc",
                    }
                )
            self._db.commit()
            node = {
                "id": node_id,
                "agent": agent,
                "content": content,
                "tags": tags,
                "kind": kind,
                "created": now,
            }
            return node, new_edges

    # -- reading knowledge (learn) --------------------------------------
    def recall(self, query, k=5):
        """Return the top-k memories most related to a query.

        Returns a list of {node, score} ranked by similarity.
        """
        qkw = linking.keywords(query)
        with self._lock:
            scored = []
            for nid, kw in self._kw.items():
                s = linking.similarity(qkw, kw)
                if s > 0:
                    scored.append((nid, s))
            scored.sort(key=lambda x: x[1], reverse=True)
            top = scored[:k]
            results = []
            for nid, s in top:
                row = self._db.execute(
                    "SELECT * FROM nodes WHERE id = ?", (nid,)
                ).fetchone()
                if row:
                    node = dict(row)
                    node["tags"] = json.loads(node["tags"])
                    results.append({"node": node, "score": round(s, 4)})
            return results

    # -- direct handoff (teach a specific agent) ------------------------
    def handoff(self, src_agent, dst_agent, content, tags=None):
        """One agent hands knowledge directly to another.

        Stored as a memory authored by src, plus a 'handoff' synapse from
        the new node toward the most relevant thing dst already knows (or
        standing alone if dst is new). Returns (node, edges).
        """
        node, edges = self.remember(src_agent, content, tags, kind="handoff")
        return node, edges

    def link(self, src, dst, weight=1.0, kind="taught"):
        """Manually assert a synapse between two nodes (explicit teaching)."""
        with self._lock:
            cur = self._db.execute(
                "INSERT INTO edges (src, dst, weight, kind, created)"
                " VALUES (?,?,?,?,?)",
                (src, dst, weight, kind, _now()),
            )
            self._db.commit()
            return {
                "id": cur.lastrowid,
                "src": src,
                "dst": dst,
                "weight": weight,
                "kind": kind,
            }

    # -- snapshot for the visualiser ------------------------------------
    def snapshot(self):
        with self._lock:
            agents = [dict(r) for r in self._db.execute("SELECT * FROM agents")]
            nodes = []
            for r in self._db.execute("SELECT * FROM nodes"):
                n = dict(r)
                n["tags"] = json.loads(n["tags"])
                nodes.append(n)
            edges = [dict(r) for r in self._db.execute("SELECT * FROM edges")]
            return {"agents": agents, "nodes": nodes, "edges": edges}

    def stats(self):
        with self._lock:
            a = self._db.execute("SELECT COUNT(*) c FROM agents").fetchone()["c"]
            n = self._db.execute("SELECT COUNT(*) c FROM nodes").fetchone()["c"]
            e = self._db.execute("SELECT COUNT(*) c FROM edges").fetchone()["c"]
            return {"agents": a, "neurons": n, "synapses": e}

    # -- live steering (change agents while they run) -------------------
    def set_directive(self, agent, patch):
        """Merge a steering patch into an agent's directive and persist it.

        `agent` may be a specific name or '*' for the global default that
        applies to every agent. Returns the merged directive.
        """
        with self._lock:
            row = self._db.execute(
                "SELECT data FROM directives WHERE agent = ?", (agent,)
            ).fetchone()
            current = json.loads(row["data"]) if row else {}
            current.update(patch or {})
            self._db.execute(
                "INSERT INTO directives (agent, data, updated) VALUES (?,?,?)"
                " ON CONFLICT(agent) DO UPDATE SET data=excluded.data,"
                " updated=excluded.updated",
                (agent, json.dumps(current), _now()),
            )
            self._db.commit()
            return current

    def get_directive(self, agent):
        """Effective directive for an agent: global defaults ('*') overlaid
        with the agent's own directive."""
        with self._lock:
            merged = {}
            for key in ("*", agent):
                row = self._db.execute(
                    "SELECT data FROM directives WHERE agent = ?", (key,)
                ).fetchone()
                if row:
                    merged.update(json.loads(row["data"]))
            return merged

    def all_directives(self):
        with self._lock:
            out = {}
            for r in self._db.execute("SELECT agent, data FROM directives"):
                out[r["agent"]] = json.loads(r["data"])
            return out

    # -- collaboration analytics ----------------------------------------
    def metrics(self):
        """How well the swarm works with each other's data.

        The headline is the *collaboration index*: the share of synapses
        that bridge two different agents' memories — i.e. how much the
        agents build on each other's knowledge rather than their own.
        Also returns per-agent contribution/reach and the strongest
        agent-to-agent pairings.
        """
        with self._lock:
            author = {}
            per_agent = {}
            for r in self._db.execute("SELECT id, agent FROM nodes"):
                author[r["id"]] = r["agent"]
                per_agent.setdefault(
                    r["agent"], {"agent": r["agent"], "neurons": 0,
                                 "reach": 0, "handoffs": 0})
                per_agent[r["agent"]]["neurons"] += 1

            cross = 0
            total = 0
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
            minds = sorted(per_agent.values(),
                           key=lambda m: (m["reach"], m["neurons"]),
                           reverse=True)
            return {
                "collaboration_index": index,
                "cross_links": cross,
                "total_links": total,
                "minds": minds,
                "pairs": [{"pair": k, "links": v} for k, v in top_pairs],
            }
