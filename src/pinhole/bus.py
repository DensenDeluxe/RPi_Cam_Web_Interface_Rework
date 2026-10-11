"""In-process event bus.

Camera threads, the scheduler and the API all publish events here. The bus
fans them out to Server-Sent-Event clients (the web UI) and to internal
subscribers such as webhooks and MQTT. Publishing is safe from any thread.
"""

from __future__ import annotations

import asyncio
import logging
import time
from collections.abc import Awaitable, Callable
from dataclasses import dataclass, field
from typing import Any

log = logging.getLogger(__name__)

# Events that are sent very often and that clients only receive when asked.
NOISY = frozenset({"motion.level"})


@dataclass(slots=True)
class Event:
    type: str
    data: dict[str, Any] = field(default_factory=dict)
    ts: float = field(default_factory=time.time)

    def as_dict(self) -> dict[str, Any]:
        return {"type": self.type, "data": self.data, "ts": self.ts}


Handler = Callable[[Event], Awaitable[None] | None]


class Subscription:
    def __init__(self, bus: EventBus, noisy: bool, maxsize: int = 256) -> None:
        self._bus = bus
        self.noisy = noisy
        self.queue: asyncio.Queue[Event] = asyncio.Queue(maxsize=maxsize)

    def offer(self, event: Event) -> None:
        if event.type in NOISY and not self.noisy:
            return
        try:
            self.queue.put_nowait(event)
        except asyncio.QueueFull:
            # A slow client: drop the oldest event rather than blocking everyone.
            try:
                self.queue.get_nowait()
                self.queue.put_nowait(event)
            except (asyncio.QueueEmpty, asyncio.QueueFull):
                pass

    async def get(self) -> Event:
        return await self.queue.get()

    def close(self) -> None:
        self._bus._subscriptions.discard(self)


class EventBus:
    def __init__(self) -> None:
        self._loop: asyncio.AbstractEventLoop | None = None
        self._subscriptions: set[Subscription] = set()
        self._handlers: list[tuple[set[str] | None, Handler]] = []
        self._noisy_listeners = 0

    def bind(self, loop: asyncio.AbstractEventLoop) -> None:
        self._loop = loop

    @property
    def wants_noisy(self) -> bool:
        return any(sub.noisy for sub in self._subscriptions)

    def subscribe(self, noisy: bool = False) -> Subscription:
        sub = Subscription(self, noisy)
        self._subscriptions.add(sub)
        return sub

    def on(self, types: set[str] | None, handler: Handler) -> None:
        """Register an internal handler for the given event types (None = all)."""
        self._handlers.append((types, handler))

    def publish(self, type_: str, **data: Any) -> None:
        event = Event(type_, data)
        loop = self._loop
        if loop is None or loop.is_closed():
            return
        try:
            running = asyncio.get_running_loop()
        except RuntimeError:
            running = None
        if running is loop:
            self._dispatch(event)
        else:
            loop.call_soon_threadsafe(self._dispatch, event)

    def _dispatch(self, event: Event) -> None:
        for sub in list(self._subscriptions):
            sub.offer(event)
        if event.type in NOISY:
            return
        for types, handler in self._handlers:
            if types is not None and event.type not in types:
                continue
            try:
                result = handler(event)
                if asyncio.iscoroutine(result):
                    task = asyncio.ensure_future(result)
                    task.add_done_callback(_log_task_error)
            except Exception:
                log.exception("event handler failed for %s", event.type)


def _log_task_error(task: asyncio.Future[Any]) -> None:
    if not task.cancelled() and task.exception():
        log.error("event handler failed", exc_info=task.exception())
