# Run the brain always-on (on your NAS or a server)

The brain should live in **one place that's always on**, so the whole team
shares the same growing memory. Your NAS is ideal. Two ways.

## Option A — Docker (recommended)

Most NAS boxes have Docker (Synology **Container Manager**, QNAP **Container
Station**, TrueNAS/Unraid). From the project folder on the NAS:

```bash
docker compose up -d
```

That builds the image, runs the brain on port **8000**, keeps its memory in a
persistent volume, and restarts it automatically after reboots. Open:

```
http://<your-nas-ip>:8000
```

To load the roster and start the Feeder against it (from any machine):

```bash
BRAIN_URL=http://<your-nas-ip>:8000 python agents/roster.py
BRAIN_URL=http://<your-nas-ip>:8000 python agents/feeder.py
```

**Synology / QNAP without the CLI:** in Container Manager/Station, create a
project from this folder's `docker-compose.yml`, or build the `Dockerfile`,
map a volume to `/data`, publish port `8000`, and set restart to
*unless-stopped*.

## Option B — plain Python

If the NAS has Python 3.11 and no Docker:

```bash
python main.py --host 0.0.0.0 --port 8000 --db /volume1/brain/brain.db
```

Keep it alive with the NAS task scheduler (run at boot) or a systemd service.

## Reaching it from your agents

- **Agents on your network** (same LAN): use `http://<nas-ip>:8000`. Done.
- **Cowork / cloud agents** (run on the internet): they can't reach a LAN
  address. Expose the brain securely with your NAS's **built-in VPN**
  (Synology/QNAP both have one) or a reverse proxy + auth, then point agents
  at that address. **Don't** expose the raw port to the internet — the brain
  has no login of its own yet.

## Check it's healthy

```bash
curl http://<host>:8000/stats     # -> {"agents":N,"neurons":N,"synapses":N}
```
