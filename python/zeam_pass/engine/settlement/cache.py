import threading
import time


class Cache:

    def get(self, key):
        raise NotImplementedError

    def set(self, key, value, ttl_seconds):
        raise NotImplementedError


class ArrayCache(Cache):
    def __init__(self, clock=None):
        self._items = {}
        self._clock = clock
        self._lock = threading.Lock()

    def _now(self):
        return float(self._clock()) if self._clock else time.time() * 1000

    def get(self, key):
        with self._lock:
            item = self._items.get(key)
            if item is None:
                return None
            value, until = item
            if until < self._now():
                del self._items[key]
                return None
            return value

    def set(self, key, value, ttl_seconds):
        with self._lock:
            self._items[key] = (value, self._now() + 1000 * float(ttl_seconds))
