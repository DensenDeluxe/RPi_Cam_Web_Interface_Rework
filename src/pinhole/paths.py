"""Runtime environment: where Pinhole keeps its data and how it is reached.

Everything here comes from environment variables so that the systemd unit
(``/etc/pinhole/pinhole.env``), the development server and the tests can each
point Pinhole somewhere else without touching code.
"""

from __future__ import annotations

import os
import shutil
from dataclasses import dataclass, field
from pathlib import Path


def _env_bool(name: str, default: bool = False) -> bool:
    value = os.environ.get(name)
    if value is None:
        return default
    return value.strip().lower() in {"1", "true", "yes", "on"}


def _default_data_dir() -> Path:
    if os.geteuid() == 0 or Path("/var/lib/pinhole").is_dir():
        return Path("/var/lib/pinhole")
    return Path.cwd() / ".pinhole-data"


def _find_mediamtx() -> Path | None:
    candidates = [
        os.environ.get("PINHOLE_MEDIAMTX"),
        str(Path(__file__).resolve().parents[2] / "bin" / "mediamtx"),
        "/opt/pinhole/current/bin/mediamtx",
        shutil.which("mediamtx"),
    ]
    for candidate in candidates:
        if candidate and Path(candidate).is_file() and os.access(candidate, os.X_OK):
            return Path(candidate)
    return None


@dataclass(slots=True)
class Environment:
    data_dir: Path
    media_dir: Path
    host: str = "0.0.0.0"
    port: int = 8080
    camera_override: str | None = None
    mediamtx_binary: Path | None = None
    dev: bool = False
    tls_cert: Path | None = None
    tls_key: Path | None = None
    release_dir: Path | None = None
    extra: dict[str, str] = field(default_factory=dict)

    @property
    def db_path(self) -> Path:
        return self.data_dir / "pinhole.db"

    @property
    def settings_path(self) -> Path:
        return self.data_dir / "settings.json"

    @property
    def secret_path(self) -> Path:
        return self.data_dir / "secret.key"

    @property
    def setup_token_path(self) -> Path:
        return self.data_dir / "setup-token"

    @property
    def run_dir(self) -> Path:
        return self.data_dir / "run"

    @property
    def scripts_dir(self) -> Path:
        return self.data_dir / "scripts"

    @property
    def thumbs_dir(self) -> Path:
        return self.media_dir / ".thumbs"

    def ensure(self) -> None:
        for directory in (self.data_dir, self.media_dir, self.run_dir, self.scripts_dir, self.thumbs_dir):
            directory.mkdir(parents=True, exist_ok=True)

    @classmethod
    def from_env(cls) -> Environment:
        data_dir = Path(os.environ.get("PINHOLE_DATA_DIR") or _default_data_dir()).expanduser()
        media_dir = Path(os.environ.get("PINHOLE_MEDIA_DIR") or data_dir / "media").expanduser()
        release = os.environ.get("PINHOLE_RELEASE_DIR")
        cert = os.environ.get("PINHOLE_TLS_CERT")
        key = os.environ.get("PINHOLE_TLS_KEY")
        return cls(
            data_dir=data_dir,
            media_dir=media_dir,
            host=os.environ.get("PINHOLE_HOST", "0.0.0.0"),
            port=int(os.environ.get("PINHOLE_PORT", "8080")),
            camera_override=os.environ.get("PINHOLE_CAMERA") or None,
            mediamtx_binary=_find_mediamtx(),
            dev=_env_bool("PINHOLE_DEV"),
            tls_cert=Path(cert) if cert else None,
            tls_key=Path(key) if key else None,
            release_dir=Path(release) if release else None,
        )
