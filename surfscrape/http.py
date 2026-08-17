"""Polite async fetcher with an on-disk conditional-GET cache.

The cache is what makes a daily re-scrape cheap: we send If-None-Match /
If-Modified-Since and a 304 costs us headers only, no body, no parsing.
"""

from __future__ import annotations

import asyncio
import logging
import sqlite3
import time
import urllib.robotparser
from dataclasses import dataclass
from pathlib import Path
from urllib.parse import urljoin, urlparse

import httpx
from tenacity import (
    retry,
    retry_if_exception_type,
    stop_after_attempt,
    wait_exponential,
)

from .config import Fetch

log = logging.getLogger(__name__)

_SCHEMA = """
CREATE TABLE IF NOT EXISTS pages (
    url TEXT PRIMARY KEY,
    etag TEXT,
    last_modified TEXT,
    body TEXT,
    content_type TEXT,
    fetched_at REAL
);
"""


@dataclass(slots=True)
class Response:
    url: str
    status: int
    text: str
    from_cache: bool = False
    unchanged: bool = False  # server said 304; body came from cache

    @property
    def ok(self) -> bool:
        return self.status < 400 or self.unchanged


class Cache:
    def __init__(self, path: str | Path):
        Path(path).parent.mkdir(parents=True, exist_ok=True)
        self.db = sqlite3.connect(str(path), check_same_thread=False)
        self.db.execute(_SCHEMA)
        self.db.commit()
        self._lock = asyncio.Lock()

    async def get(self, url: str) -> tuple[str | None, str | None, str | None]:
        async with self._lock:
            row = self.db.execute(
                "SELECT etag, last_modified, body FROM pages WHERE url = ?", (url,)
            ).fetchone()
        return row if row else (None, None, None)

    async def put(self, url: str, etag: str | None, lm: str | None, body: str, ctype: str) -> None:
        async with self._lock:
            self.db.execute(
                "INSERT OR REPLACE INTO pages VALUES (?,?,?,?,?,?)",
                (url, etag, lm, body, ctype, time.time()),
            )
            self.db.commit()

    async def touch(self, url: str) -> None:
        async with self._lock:
            self.db.execute("UPDATE pages SET fetched_at = ? WHERE url = ?", (time.time(), url))
            self.db.commit()

    def close(self) -> None:
        self.db.close()


class Fetcher:
    """One Fetcher per shop. Owns concurrency, pacing, robots and the cache."""

    def __init__(self, base_url: str, cfg: Fetch, cache_path: str | Path | None = None):
        self.base_url = base_url
        self.cfg = cfg
        headers = {"User-Agent": cfg.user_agent, "Accept-Language": "sl,en;q=0.8"}
        headers.update(cfg.headers)
        self.client = httpx.AsyncClient(
            headers=headers,
            timeout=cfg.timeout,
            follow_redirects=True,
            verify=cfg.verify_tls,
            http2=False,
        )
        self.sem = asyncio.Semaphore(cfg.concurrency)
        self.cache = Cache(cache_path) if cache_path else None
        self._robots: urllib.robotparser.RobotFileParser | None = None
        self._last_request = 0.0
        self._pace_lock = asyncio.Lock()
        self.stats = {"requests": 0, "not_modified": 0, "errors": 0, "blocked": 0}

    async def __aenter__(self) -> Fetcher:
        if self.cfg.respect_robots:
            await self.load_robots()
        return self

    async def __aexit__(self, *exc) -> None:
        await self.close()

    async def close(self) -> None:
        await self.client.aclose()
        if self.cache:
            self.cache.close()

    # ---- robots -------------------------------------------------------
    async def load_robots(self) -> None:
        url = urljoin(self.base_url, "/robots.txt")
        rp = urllib.robotparser.RobotFileParser()
        try:
            r = await self.client.get(url)
            rp.parse(r.text.splitlines() if r.status_code == 200 else [])
        except Exception as exc:  # network hiccup: fail open, we have permission
            log.warning("robots.txt unreachable (%s): %s", url, exc)
            rp.parse([])
        rp.set_url(url)
        self._robots = rp

    def allowed(self, url: str) -> bool:
        if not self.cfg.respect_robots or self._robots is None:
            return True
        return self._robots.can_fetch(self.cfg.user_agent, url)

    def sitemaps_from_robots(self) -> list[str]:
        if self._robots is None:
            return []
        return list(getattr(self._robots, "sitemaps", None) or [])

    def crawl_delay(self) -> float:
        if self._robots is None:
            return self.cfg.delay
        try:
            d = self._robots.crawl_delay(self.cfg.user_agent)
        except Exception:
            d = None
        return max(self.cfg.delay, float(d)) if d else self.cfg.delay

    # ---- fetching -----------------------------------------------------
    async def _pace(self) -> None:
        delay = self.crawl_delay()
        if delay <= 0:
            return
        async with self._pace_lock:
            wait = self._last_request + delay - time.monotonic()
            if wait > 0:
                await asyncio.sleep(wait)
            self._last_request = time.monotonic()

    async def get(self, url: str, *, use_cache: bool = True) -> Response:
        if not self.allowed(url):
            self.stats["blocked"] += 1
            log.info("robots.txt disallows %s", url)
            return Response(url=url, status=999, text="")
        async with self.sem:
            return await self._get_inner(url, use_cache)

    @retry(
        stop=stop_after_attempt(3),
        wait=wait_exponential(multiplier=1, min=2, max=20),
        retry=retry_if_exception_type((httpx.TransportError, httpx.HTTPStatusError)),
        reraise=False,
    )
    async def _request(self, url: str, headers: dict[str, str]) -> httpx.Response:
        await self._pace()
        r = await self.client.get(url, headers=headers)
        if r.status_code in (429, 500, 502, 503, 504):
            r.raise_for_status()
        return r

    async def _get_inner(self, url: str, use_cache: bool) -> Response:
        headers: dict[str, str] = {}
        cached_body = None
        if use_cache and self.cache:
            etag, lm, cached_body = await self.cache.get(url)
            if etag:
                headers["If-None-Match"] = etag
            if lm:
                headers["If-Modified-Since"] = lm
        try:
            r = await self._request(url, headers)
        except Exception as exc:
            self.stats["errors"] += 1
            log.warning("GET %s failed: %s", url, exc)
            if cached_body:
                return Response(url=url, status=200, text=cached_body, from_cache=True)
            return Response(url=url, status=0, text="")

        self.stats["requests"] += 1
        if r.status_code == 304 and cached_body is not None:
            self.stats["not_modified"] += 1
            if self.cache:
                await self.cache.touch(url)
            return Response(url=str(r.url), status=200, text=cached_body,
                            from_cache=True, unchanged=True)
        if r.status_code >= 400:
            self.stats["errors"] += 1
            return Response(url=str(r.url), status=r.status_code, text="")

        if self.cache:
            await self.cache.put(
                url,
                r.headers.get("ETag"),
                r.headers.get("Last-Modified"),
                r.text,
                r.headers.get("Content-Type", ""),
            )
        return Response(url=str(r.url), status=r.status_code, text=r.text)

    async def get_json(self, url: str, *, use_cache: bool = True):
        import json

        r = await self.get(url, use_cache=use_cache)
        if not r.ok or not r.text:
            return None
        try:
            return json.loads(r.text)
        except ValueError:
            return None


def same_host(url: str, base: str) -> bool:
    a, b = urlparse(url).netloc.lower(), urlparse(base).netloc.lower()
    return a.removeprefix("www.") == b.removeprefix("www.")
