"""SQLite storage for users, sessions, API tokens, the media index and events."""

from __future__ import annotations

import sqlite3
import threading
from collections.abc import Iterable, Iterator
from contextlib import contextmanager
from pathlib import Path
from typing import Any

MIGRATIONS: list[str] = [
    # 1 – initial schema
    """
    CREATE TABLE users (
        id INTEGER PRIMARY KEY,
        username TEXT NOT NULL UNIQUE COLLATE NOCASE,
        password_hash TEXT NOT NULL,
        role TEXT NOT NULL CHECK (role IN ('viewer', 'operator', 'admin')),
        created_at REAL NOT NULL,
        last_login REAL
    );
    CREATE TABLE sessions (
        token_hash TEXT PRIMARY KEY,
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        created_at REAL NOT NULL,
        expires_at REAL NOT NULL,
        last_seen REAL NOT NULL,
        user_agent TEXT,
        ip TEXT
    );
    CREATE INDEX sessions_user ON sessions(user_id);
    CREATE TABLE api_tokens (
        id INTEGER PRIMARY KEY,
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        name TEXT NOT NULL,
        token_hash TEXT NOT NULL UNIQUE,
        prefix TEXT NOT NULL,
        created_at REAL NOT NULL,
        last_used REAL
    );
    CREATE TABLE media (
        id TEXT PRIMARY KEY,
        kind TEXT NOT NULL CHECK (kind IN ('video', 'photo', 'timelapse')),
        path TEXT NOT NULL UNIQUE,
        created_at REAL NOT NULL,
        size INTEGER NOT NULL DEFAULT 0,
        duration REAL,
        width INTEGER,
        height INTEGER,
        frames INTEGER,
        locked INTEGER NOT NULL DEFAULT 0,
        trigger TEXT NOT NULL DEFAULT 'manual',
        event_id TEXT,
        thumb TEXT,
        status TEXT NOT NULL DEFAULT 'ready'
    );
    CREATE INDEX media_created ON media(created_at DESC);
    CREATE INDEX media_kind ON media(kind, created_at DESC);
    CREATE TABLE events (
        id TEXT PRIMARY KEY,
        kind TEXT NOT NULL,
        started_at REAL NOT NULL,
        ended_at REAL,
        peak REAL,
        thumb TEXT,
        data TEXT
    );
    CREATE INDEX events_started ON events(started_at DESC);
    CREATE TABLE kv (key TEXT PRIMARY KEY, value TEXT NOT NULL);
    """,
]


class Database:
    """A single shared connection guarded by a lock.

    Pinhole is a single-process appliance with little write concurrency, so one
    connection in WAL mode is simpler and faster than a pool.
    """

    def __init__(self, path: Path | str) -> None:
        self.path = str(path)
        self._lock = threading.RLock()
        self._conn = sqlite3.connect(self.path, check_same_thread=False, isolation_level=None, timeout=10)
        self._conn.row_factory = sqlite3.Row
        with self._lock:
            if self.path != ":memory:":
                self._conn.execute("PRAGMA journal_mode=WAL")
            self._conn.execute("PRAGMA foreign_keys=ON")
            self._conn.execute("PRAGMA synchronous=NORMAL")
            self._migrate()

    def _migrate(self) -> None:
        version = self._conn.execute("PRAGMA user_version").fetchone()[0]
        for index, script in enumerate(MIGRATIONS[version:], start=version + 1):
            self._conn.executescript(f"BEGIN; {script}; PRAGMA user_version={index}; COMMIT;")

    @contextmanager
    def transaction(self) -> Iterator[sqlite3.Connection]:
        with self._lock:
            self._conn.execute("BEGIN")
            try:
                yield self._conn
            except BaseException:
                self._conn.execute("ROLLBACK")
                raise
            self._conn.execute("COMMIT")

    def execute(self, sql: str, params: Iterable[Any] | dict[str, Any] = ()) -> sqlite3.Cursor:
        with self._lock:
            return self._conn.execute(sql, params)

    def one(self, sql: str, params: Iterable[Any] | dict[str, Any] = ()) -> dict[str, Any] | None:
        with self._lock:
            row = self._conn.execute(sql, params).fetchone()
        return dict(row) if row else None

    def all(self, sql: str, params: Iterable[Any] | dict[str, Any] = ()) -> list[dict[str, Any]]:
        with self._lock:
            rows = self._conn.execute(sql, params).fetchall()
        return [dict(row) for row in rows]

    def scalar(self, sql: str, params: Iterable[Any] | dict[str, Any] = ()) -> Any:
        with self._lock:
            row = self._conn.execute(sql, params).fetchone()
        return row[0] if row else None

    def kv_get(self, key: str, default: str | None = None) -> str | None:
        value = self.scalar("SELECT value FROM kv WHERE key = ?", (key,))
        return default if value is None else str(value)

    def kv_set(self, key: str, value: str) -> None:
        self.execute(
            "INSERT INTO kv(key, value) VALUES(?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value",
            (key, value),
        )

    def close(self) -> None:
        with self._lock:
            self._conn.close()
