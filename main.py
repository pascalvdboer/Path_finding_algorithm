"""Wake the Agent Brain.

    python main.py                 # http://127.0.0.1:8000
    python main.py --port 9000
    python main.py --host 0.0.0.0  # let other machines' agents connect

Open the visualiser URL in a browser to watch the brain think.
"""

import argparse

from brain.server import serve


def main():
    p = argparse.ArgumentParser(description="The Agent Brain — a shared cortex.")
    p.add_argument("--host", default="127.0.0.1")
    p.add_argument("--port", type=int, default=8000)
    p.add_argument("--db", default="brain.db", help="where memories are stored")
    args = p.parse_args()
    serve(host=args.host, port=args.port, db_path=args.db)


if __name__ == "__main__":
    main()
