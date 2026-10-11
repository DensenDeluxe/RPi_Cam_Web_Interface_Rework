"""Users, browser sessions, API tokens and the first-run setup code."""

from __future__ import annotations

import os
import re
import secrets
import threading
import time
from dataclasses import dataclass
from pathlib import Path
from typing import Literal

from pinhole.auth.passwords import hash_password, token_hash, verify_password
from pinhole.db import Database

Role = Literal["viewer", "operator", "admin"]
ROLE_LEVEL: dict[str, int] = {"viewer": 1, "operator": 2, "admin": 3}

SESSION_DAYS = 30
SESSION_REFRESH_SECONDS = 300
USERNAME_RE = re.compile(r"^[A-Za-z0-9._-]{1,32}$")
MIN_PASSWORD = 8
SETUP_ALPHABET = "ABCDEFGHJKLMNPQRSTUVWXYZ23456789"


class AuthError(Exception):
    def __init__(self, code: str, message: str, retry_after: float | None = None) -> None:
        super().__init__(message)
        self.code = code
        self.retry_after = retry_after


@dataclass(slots=True, frozen=True)
class User:
    id: int
    username: str
    role: Role
    via_token: bool = False

    def can(self, role: Role) -> bool:
        return ROLE_LEVEL[self.role] >= ROLE_LEVEL[role]


ANONYMOUS_VIEWER = User(id=0, username="guest", role="viewer")


class _Throttle:
    """Exponential back-off for failed logins, per client and per username."""

    def __init__(self) -> None:
        self._failures: dict[str, tuple[int, float]] = {}
        self._lock = threading.Lock()

    def check(self, *keys: str) -> None:
        now = time.monotonic()
        with self._lock:
            for key in keys:
                count, until = self._failures.get(key, (0, 0.0))
                if until > now:
                    raise AuthError("too_many_attempts", "Too many attempts, try again later", until - now)

    def fail(self, *keys: str) -> None:
        now = time.monotonic()
        with self._lock:
            for key in keys:
                count, _ = self._failures.get(key, (0, 0.0))
                count += 1
                delay = 0.0 if count < 5 else min(2 ** (count - 5), 300)
                self._failures[key] = (count, now + delay)
            if len(self._failures) > 10_000:
                self._failures.clear()

    def succeed(self, *keys: str) -> None:
        with self._lock:
            for key in keys:
                self._failures.pop(key, None)


class AuthService:
    def __init__(self, db: Database, setup_token_path: Path) -> None:
        self.db = db
        self._setup_path = setup_token_path
        self._throttle = _Throttle()
        self._setup_code: str | None = None
        if not self.has_users():
            self._setup_code = self._load_or_create_setup_code()

    # ----------------------------------------------------------------- setup
    def _load_or_create_setup_code(self) -> str:
        try:
            code = self._setup_path.read_text("ascii").strip()
            if len(code) == 9:
                return code
        except OSError:
            pass
        raw = "".join(secrets.choice(SETUP_ALPHABET) for _ in range(8))
        code = f"{raw[:4]}-{raw[4:]}"
        self._setup_path.parent.mkdir(parents=True, exist_ok=True)
        fd = os.open(self._setup_path, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o640)
        with os.fdopen(fd, "w") as handle:
            handle.write(code + "\n")
        return code

    @property
    def setup_required(self) -> bool:
        return self._setup_code is not None

    def check_setup_code(self, code: str, client: str) -> None:
        if self._setup_code is None:
            raise AuthError("setup_done", "Pinhole is already set up")
        self._throttle.check(f"setup:{client}")
        normalized = code.strip().upper().replace(" ", "")
        if len(normalized) == 8:
            normalized = f"{normalized[:4]}-{normalized[4:]}"
        if not secrets.compare_digest(normalized, self._setup_code):
            self._throttle.fail(f"setup:{client}")
            raise AuthError("invalid_setup_code", "The setup code is not correct")

    def complete_setup(self, code: str, username: str, password: str, client: str) -> User:
        self.check_setup_code(code, client)
        user = self.create_user(username, password, "admin")
        self._setup_code = None
        self._setup_path.unlink(missing_ok=True)
        return user

    # ----------------------------------------------------------------- users
    def has_users(self) -> bool:
        return bool(self.db.scalar("SELECT EXISTS(SELECT 1 FROM users)"))

    @staticmethod
    def validate_credentials(username: str, password: str | None) -> None:
        if not USERNAME_RE.match(username):
            raise AuthError("invalid_username", "Use 1–32 letters, digits, dots, dashes or underscores")
        if password is not None and len(password) < MIN_PASSWORD:
            raise AuthError("weak_password", f"The password needs at least {MIN_PASSWORD} characters")

    def create_user(self, username: str, password: str, role: Role) -> User:
        self.validate_credentials(username, password)
        if role not in ROLE_LEVEL:
            raise AuthError("invalid_role", "Unknown role")
        if self.db.one("SELECT id FROM users WHERE username = ?", (username,)):
            raise AuthError("user_exists", "This user name is taken")
        cursor = self.db.execute(
            "INSERT INTO users(username, password_hash, role, created_at) VALUES (?, ?, ?, ?)",
            (username, hash_password(password), role, time.time()),
        )
        return User(int(cursor.lastrowid or 0), username, role)

    def list_users(self) -> list[dict[str, object]]:
        return self.db.all("SELECT id, username, role, created_at, last_login FROM users ORDER BY username")

    def get_user(self, user_id: int) -> User | None:
        row = self.db.one("SELECT id, username, role FROM users WHERE id = ?", (user_id,))
        return User(row["id"], row["username"], row["role"]) if row else None

    def update_user(self, user_id: int, *, role: Role | None = None, password: str | None = None) -> None:
        user = self.get_user(user_id)
        if not user:
            raise AuthError("not_found", "User not found")
        if role and role != user.role:
            if role not in ROLE_LEVEL:
                raise AuthError("invalid_role", "Unknown role")
            if user.role == "admin" and self._admin_count() <= 1:
                raise AuthError("last_admin", "At least one administrator is required")
            self.db.execute("UPDATE users SET role = ? WHERE id = ?", (role, user_id))
        if password is not None:
            self.validate_credentials(user.username, password)
            self.db.execute("UPDATE users SET password_hash = ? WHERE id = ?", (hash_password(password), user_id))
            self.db.execute("DELETE FROM sessions WHERE user_id = ?", (user_id,))

    def delete_user(self, user_id: int) -> None:
        user = self.get_user(user_id)
        if not user:
            raise AuthError("not_found", "User not found")
        if user.role == "admin" and self._admin_count() <= 1:
            raise AuthError("last_admin", "At least one administrator is required")
        self.db.execute("DELETE FROM users WHERE id = ?", (user_id,))

    def _admin_count(self) -> int:
        return int(self.db.scalar("SELECT COUNT(*) FROM users WHERE role = 'admin'") or 0)

    def change_password(self, user: User, current: str, new: str) -> None:
        row = self.db.one("SELECT password_hash FROM users WHERE id = ?", (user.id,))
        if not row or not verify_password(current, row["password_hash"]):
            raise AuthError("invalid_credentials", "The current password is not correct")
        self.update_user(user.id, password=new)

    def reset_password(self, username: str, new: str) -> None:
        row = self.db.one("SELECT id FROM users WHERE username = ?", (username,))
        if not row:
            raise AuthError("not_found", "User not found")
        self.update_user(row["id"], password=new)

    # -------------------------------------------------------------- sessions
    def login(self, username: str, password: str, client: str) -> User:
        keys = (f"ip:{client}", f"user:{username.lower()}")
        self._throttle.check(*keys)
        row = self.db.one("SELECT id, username, role, password_hash FROM users WHERE username = ?", (username,))
        if not row or not verify_password(password, row["password_hash"]):
            if not row:
                # Spend the same time as a real check so user names can't be probed.
                verify_password(password, hash_password("timing-equaliser"))
            self._throttle.fail(*keys)
            raise AuthError("invalid_credentials", "User name or password is not correct")
        self._throttle.succeed(*keys)
        self.db.execute("UPDATE users SET last_login = ? WHERE id = ?", (time.time(), row["id"]))
        return User(row["id"], row["username"], row["role"])

    def create_session(self, user: User, user_agent: str = "", ip: str = "") -> str:
        token = secrets.token_urlsafe(32)
        now = time.time()
        self.db.execute(
            "INSERT INTO sessions(token_hash, user_id, created_at, expires_at, last_seen, user_agent, ip) "
            "VALUES (?, ?, ?, ?, ?, ?, ?)",
            (token_hash(token), user.id, now, now + SESSION_DAYS * 86400, now, user_agent[:200], ip),
        )
        return token

    def session_user(self, token: str) -> User | None:
        if not token or len(token) > 200:
            return None
        hashed = token_hash(token)
        row = self.db.one(
            "SELECT s.expires_at, s.last_seen, u.id, u.username, u.role FROM sessions s "
            "JOIN users u ON u.id = s.user_id WHERE s.token_hash = ?",
            (hashed,),
        )
        now = time.time()
        if not row:
            return None
        if row["expires_at"] < now:
            self.db.execute("DELETE FROM sessions WHERE token_hash = ?", (hashed,))
            return None
        if now - row["last_seen"] > SESSION_REFRESH_SECONDS:
            self.db.execute(
                "UPDATE sessions SET last_seen = ?, expires_at = ? WHERE token_hash = ?",
                (now, now + SESSION_DAYS * 86400, hashed),
            )
        return User(row["id"], row["username"], row["role"])

    def logout(self, token: str) -> None:
        self.db.execute("DELETE FROM sessions WHERE token_hash = ?", (token_hash(token),))

    def purge_expired(self) -> None:
        self.db.execute("DELETE FROM sessions WHERE expires_at < ?", (time.time(),))

    # ------------------------------------------------------------ API tokens
    def create_token(self, user: User, name: str) -> tuple[int, str]:
        name = name.strip()[:60] or "API token"
        token = "ph_" + secrets.token_urlsafe(30)
        cursor = self.db.execute(
            "INSERT INTO api_tokens(user_id, name, token_hash, prefix, created_at) VALUES (?, ?, ?, ?, ?)",
            (user.id, name, token_hash(token), token[:7], time.time()),
        )
        return int(cursor.lastrowid or 0), token

    def list_tokens(self, user: User | None = None) -> list[dict[str, object]]:
        sql = (
            "SELECT t.id, t.name, t.prefix, t.created_at, t.last_used, u.username "
            "FROM api_tokens t JOIN users u ON u.id = t.user_id"
        )
        if user:
            return self.db.all(sql + " WHERE t.user_id = ? ORDER BY t.created_at DESC", (user.id,))
        return self.db.all(sql + " ORDER BY t.created_at DESC")

    def delete_token(self, token_id: int, user: User) -> None:
        if user.role == "admin":
            self.db.execute("DELETE FROM api_tokens WHERE id = ?", (token_id,))
        else:
            self.db.execute("DELETE FROM api_tokens WHERE id = ? AND user_id = ?", (token_id, user.id))

    def token_user(self, token: str) -> User | None:
        if not token.startswith("ph_") or len(token) > 200:
            return None
        hashed = token_hash(token)
        row = self.db.one(
            "SELECT t.id AS token_id, t.last_used, u.id, u.username, u.role FROM api_tokens t "
            "JOIN users u ON u.id = t.user_id WHERE t.token_hash = ?",
            (hashed,),
        )
        if not row:
            return None
        now = time.time()
        if not row["last_used"] or now - row["last_used"] > 60:
            self.db.execute("UPDATE api_tokens SET last_used = ? WHERE id = ?", (now, row["token_id"]))
        return User(row["id"], row["username"], row["role"], via_token=True)
