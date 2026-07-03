# Install note for the NAS agent — set up the Agent Brain

Hand this file to the agent/operator installing on the NAS. It installs and
runs the whole brain (brain + Professor/Feeder) as always-on containers, then
verifies it. Everything is dependency-free Python packaged in Docker.

Repository: `pascalvdboer/path_finding_algorithm`
Branch:     `claude/build-possibilities-edf9tr`

---

## 0. Requirements

- Docker + Docker Compose on the NAS (Synology **Container Manager**, QNAP
  **Container Station**, TrueNAS/Unraid, or Docker CLI).
- Outbound internet to pull the base image `python:3.11-slim` (one-time).
- A free TCP port (default **8000**).

## 1. Get the code

```bash
# with git:
git clone https://github.com/pascalvdboer/path_finding_algorithm.git
cd path_finding_algorithm
git checkout claude/build-possibilities-edf9tr

# OR without git: download the branch ZIP, unzip, and cd into the folder.
```

## 2. Start it (brain + Professor, always-on)

Set the options first (all optional; sensible defaults if omitted), then start:

```bash
# a shared secret so only your agents can reach a public/tunnel deployment:
export BRAIN_TOKEN="choose-a-long-secret"
# what the team is expert in: seo (default) | stockmarket | your own pack:
export BRAIN_DOMAIN="seo"
# (optional) let the Feeder source new knowledge from reliable/primary sources:
# export BRAIN_SEARCH_URL="https://your-search-bridge?q={topic}"
# export BRAIN_TRUSTED_SOURCES="developers.google.com,schema.org,web.dev"

docker compose up -d --build
```

This builds the image and starts two always-on services:
- **brain** — the dashboard + API on port 8000, memory persisted in a volume.
- **feeder** — loads the agent roster and runs the Feeder / Professor, which
  keeps teaching the brain toward the team's needs.

Both restart automatically after reboots.

> With a token set, open the dashboard as `http://<host>:8000/?token=<secret>`
> and give agents the same token (as `?token=...` or the `X-Brain-Token` header).

## 3. Verify it's healthy

```bash
curl http://localhost:8000/stats
# -> {"agents":N,"neurons":N,"synapses":N,"gaps":N}   (neurons grow as it seeds)

curl "http://localhost:8000/analyze"
# -> the Professor's needs analysis (recommendations)
```

Open the dashboard in a browser: **http://<nas-ip>:8000** — you should see the
glowing brain with the side panel (training %, minds, needs, growth).

## 4. Make it reachable for the team's agents

- **Agents on the same LAN:** they use `http://<nas-ip>:8000` directly.
- **Cloud / Cowork agents (over the internet):** do NOT expose port 8000 raw.
  Put it behind the NAS's built-in **VPN** (Synology/QNAP both have one) or a
  reverse proxy with authentication, then give agents that address.

## 5. Connect the team

Give each agent the files in **`team_kit/`**:
- `AGENT_INSTRUCTIONS.md` — the habits (be taught → recall → remember → hand off)
- `brain_client.py` — the connector
- set `BRAIN_URL` to `http://<nas-ip>:8000`

Verify a connection from any machine:
```bash
BRAIN_URL=http://<nas-ip>:8000 python team_kit/connect.py
```

## 6. (Optional) Let the Professor source the web for gaps

The brain notices what the team needs but lacks (knowledge gaps). To let the
Feeder fetch new material for those gaps, give it a search endpoint that
returns JSON `[{"text": "...", "tags": ["field"]}]`:

Edit `docker-compose.yml`, under the `feeder` service, uncomment and set:
```yaml
      - BRAIN_SEARCH_URL=https://your-search-proxy/seo?q={topic}
```
Then `docker compose up -d`. Without it, gaps are simply reported (safe default).

## 7. Updating later (new code)

```bash
cd path_finding_algorithm
git pull origin claude/build-possibilities-edf9tr
docker compose up -d --build          # rebuild & restart; memory is preserved
```
The brain's memory lives in the `brain-data` volume, so updates never wipe
what the team has learned.

## Handy commands

```bash
docker compose logs -f brain          # watch the brain
docker compose logs -f feeder         # watch the Professor teaching
docker compose restart                # restart everything
docker compose down                   # stop (data is kept in the volume)
```

## What runs where (summary)

| Service | What it does | Port |
|---|---|---|
| `brain` | Storage, teaching, recall, dashboard, API | 8000 |
| `feeder` | Roster + Feeder/Professor (teaches to needs) | — |
| team agents | Connect from anywhere via `team_kit` | — |
