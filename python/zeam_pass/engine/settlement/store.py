import glob
import os
import re

from ..files import atomic_write, ensure_dir, locked, read_json
from .jsonx import Json

_CHANNEL = re.compile(r"^0x[0-9a-f]{64}$")


class Store:

    def get(self, channel_id):
        raise NotImplementedError

    def update(self, channel_id, fn):
        raise NotImplementedError

    def list(self):
        raise NotImplementedError


class FileStore(Store):

    def __init__(self, directory):
        directory = str(directory).rstrip("/")
        if directory == "":
            raise ValueError("a file store needs a directory")
        try:
            ensure_dir(directory)
        except OSError:
            raise RuntimeError(f"cannot create the channel directory {directory}")
        self.dir = directory

    @staticmethod
    def key(channel_id):
        ident = str(channel_id).lower() if channel_id is not None else ""
        if not _CHANNEL.match(ident):
            raise ValueError("a channel id is 32 bytes of hex")
        return ident

    def _path(self, channel_id):
        return os.path.join(self.dir, FileStore.key(channel_id) + ".json")

    def get(self, channel_id):
        value = read_json(self._path(channel_id))
        return value if isinstance(value, dict) else None

    def update(self, channel_id, fn):
        path = self._path(channel_id)
        with locked(path + ".lock"):
            current = read_json(path)
            current = current if isinstance(current, dict) else None
            nxt = fn(current)
            if nxt is None:
                if os.path.exists(path):
                    os.unlink(path)
            else:
                atomic_write(path, Json.encode(nxt))
            return nxt

    def list(self):
        out = []
        for file in sorted(glob.glob(os.path.join(self.dir, "0x*.json"))):
            record = self.get(os.path.basename(file)[:-5])
            if record is not None:
                out.append(record)
        return out


class StateFile:

    def __init__(self, directory, name):
        self.path = os.path.join(ensure_dir(str(directory)), name)

    def get(self):
        return read_json(self.path)

    def set(self, value):
        atomic_write(self.path, Json.encode(value))

    def update(self, fn):
        with locked(self.path + ".lock"):
            nxt = fn(read_json(self.path))
            atomic_write(self.path, Json.encode(nxt))
            return nxt
