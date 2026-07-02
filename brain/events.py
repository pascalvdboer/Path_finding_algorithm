"""A tiny thread-safe pub/sub so the brain can broadcast activity the
instant it happens — to the visualiser and to any agent waiting on a
handoff. No polling, no dependencies.
"""

import queue
import threading


class EventBus:
    def __init__(self):
        self._lock = threading.Lock()
        self._subs = set()  # set of Queue

    def subscribe(self):
        q = queue.Queue(maxsize=1000)
        with self._lock:
            self._subs.add(q)
        return q

    def unsubscribe(self, q):
        with self._lock:
            self._subs.discard(q)

    def publish(self, event):
        """Fan an event out to every subscriber. Never blocks on a slow
        or dead subscriber — drops to that one instead of stalling the
        brain."""
        with self._lock:
            subs = list(self._subs)
        for q in subs:
            try:
                q.put_nowait(event)
            except queue.Full:
                pass
