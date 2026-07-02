"""One command to run the whole brain on your own PC.

    python run.py

Starts the brain, loads the agent roster, starts the Feeder (brain stem),
opens the dashboard in your browser, and streams a live team of requests
through it — so you just watch the brain work. Press Ctrl+C to stop
everything.

No installs needed: this uses only the Python standard library.
"""

import atexit
import os
import subprocess
import sys
import time
import urllib.request
import webbrowser

HERE = os.path.dirname(os.path.abspath(__file__))
HOST = os.environ.get("BRAIN_HOST", "127.0.0.1")
PORT = os.environ.get("BRAIN_PORT", "8000")
URL = f"http://{HOST}:{PORT}"
PY = sys.executable
procs = []


def spawn(*args):
    p = subprocess.Popen([PY, *args], cwd=HERE)
    procs.append(p)
    return p


def stop_all():
    for p in procs:
        try:
            p.terminate()
        except Exception:
            pass


def wait_up(timeout=15):
    end = time.time() + timeout
    while time.time() < end:
        try:
            urllib.request.urlopen(f"{URL}/stats", timeout=1)
            return True
        except Exception:
            time.sleep(0.3)
    return False


def main():
    atexit.register(stop_all)
    os.environ["BRAIN_URL"] = URL

    print(f"\n  🧠  Starting the Agent Brain on {URL} …")
    spawn("main.py", "--host", HOST, "--port", PORT)
    if not wait_up():
        print("  ✗ the brain did not start — is the port free?")
        return

    print("  → loading the agent roster")
    subprocess.run([PY, os.path.join("agents", "roster.py")], cwd=HERE)

    print("  → starting the Feeder (brain stem)")
    spawn(os.path.join("agents", "feeder.py"))

    print(f"  → opening the dashboard: {URL}")
    try:
        webbrowser.open(URL)
    except Exception:
        pass

    print("  → streaming a live team through the brain\n")
    print("  Watch the browser. Press Ctrl+C here to stop everything.\n")
    spawn(os.path.join("examples", "live_demo.py"))

    try:
        while True:
            time.sleep(1)
    except KeyboardInterrupt:
        print("\n  🧠  Stopping. Memories are saved in brain.db.\n")
        stop_all()


if __name__ == "__main__":
    main()
