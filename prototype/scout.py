#!/usr/bin/env python3
"""
scout.py - veille annonces logement locatif intermediaire / social en Ile-de-France.

Config-driven: adding a new landlord = adding a block in sources.yaml, no code change.

Usage:
    python scout.py --dump inli          # print raw payload of one source (field mapping)
    python scout.py --once               # single pass, print new matches
    python scout.py --once --no-state    # ignore seen.json, print everything matching
    python scout.py --watch              # loop forever with jitter
"""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import random
import re
import smtplib
import sys
import time
from dataclasses import dataclass, field, asdict
from email.message import EmailMessage
from pathlib import Path
from typing import Any, Iterable

import requests
import yaml
from bs4 import BeautifulSoup

HERE = Path(__file__).resolve().parent
STATE_PATH = HERE / "seen.json"
CONFIG_PATH = HERE / "sources.yaml"

UA = (
    "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 "
    "(KHTML, like Gecko) Chrome/126.0 Safari/537.36"
)


# --------------------------------------------------------------------------- #
# Model
# --------------------------------------------------------------------------- #

@dataclass
class Listing:
    source: str
    ref: str = ""
    title: str = ""
    url: str = ""
    commune: str = ""
    cp: str = ""
    rent: float | None = None          # loyer charges comprises si dispo
    surface: float | None = None
    rooms: int | None = None
    floor: int | None = None
    elevator: bool | None = None
    raw_text: str = ""
    raw: dict = field(default_factory=dict, repr=False)

    @property
    def uid(self) -> str:
        seed = self.ref or self.url or self.title
        return hashlib.sha1(f"{self.source}|{seed}".encode()).hexdigest()[:16]

    def line(self) -> str:
        bits = [f"[{self.source}]", self.commune or "?"]
        if self.rooms:
            bits.append(f"T{self.rooms}")
        if self.surface:
            bits.append(f"{self.surface:.0f}m2")
        if self.rent:
            bits.append(f"{self.rent:.0f}EUR")
        if self.floor is not None:
            bits.append(f"et.{self.floor}")
        if self.elevator is not None:
            bits.append("ASC" if self.elevator else "sans-asc")
        return " | ".join(bits) + f"\n    {self.title}\n    {self.url}"


# --------------------------------------------------------------------------- #
# Criteria
# --------------------------------------------------------------------------- #

@dataclass
class Criteria:
    communes: list[str] = field(default_factory=list)   # lowercase, substring match
    cp_prefixes: list[str] = field(default_factory=list)
    min_rooms: int | None = None
    min_surface: float | None = None
    max_rent: float | None = None
    max_floor: int | None = None          # applies only when floor is known
    require_elevator: bool = False        # true => reject known-no-elevator
    exclude_regex: str | None = None

    def matches(self, l: Listing) -> tuple[bool, str]:
        hay = f"{l.commune} {l.cp} {l.title} {l.raw_text}".lower()

        if self.communes and not any(c in hay for c in self.communes):
            return False, "commune"
        if self.cp_prefixes and not any(l.cp.startswith(p) for p in self.cp_prefixes):
            if not any(p in hay for p in self.cp_prefixes):
                return False, "cp"
        if self.min_rooms and (l.rooms or 0) < self.min_rooms:
            return False, "rooms"
        if self.min_surface and (l.surface or 0) < self.min_surface:
            return False, "surface"
        if self.max_rent and l.rent and l.rent > self.max_rent:
            return False, "rent"
        if self.max_floor is not None and l.floor is not None and l.floor > self.max_floor:
            # high floor is fine if there is a lift
            if not (l.elevator is True):
                return False, "floor"
        if self.require_elevator and l.elevator is False:
            return False, "no-elevator"
        if self.exclude_regex and re.search(self.exclude_regex, hay, re.I):
            return False, "excluded"
        return True, ""


# --------------------------------------------------------------------------- #
# Field extraction helpers
# --------------------------------------------------------------------------- #

def dig(obj: Any, path: str, default=None):
    """dig(d, 'results.items.0.price') -> nested lookup, list-index aware."""
    if not path:
        return default
    cur = obj
    for part in path.split("."):
        if cur is None:
            return default
        if isinstance(cur, list):
            try:
                cur = cur[int(part)]
            except (ValueError, IndexError):
                return default
        elif isinstance(cur, dict):
            cur = cur.get(part)
        else:
            return default
    return cur if cur is not None else default


NUM_RE = re.compile(r"(\d+(?:[.,]\d+)?)")


def to_num(v) -> float | None:
    if v is None:
        return None
    if isinstance(v, (int, float)):
        return float(v)
    m = NUM_RE.search(str(v).replace("\u202f", "").replace(" ", ""))
    return float(m.group(1).replace(",", ".")) if m else None


def to_int(v) -> int | None:
    n = to_num(v)
    return int(n) if n is not None else None


FLOOR_RE = re.compile(
    r"(?:(\d{1,2})\s*(?:er|eme|ème|e)\s*(?:é|e)tage)"
    r"|(?:(?:é|e)tage\s*[:n°]*\s*(\d{1,2}))"
    r"|\b(rez[- ]de[- ]chauss|rdc)\b",
    re.I,
)


def sniff_floor(text: str) -> int | None:
    """Last-resort floor extraction from free text."""
    m = FLOOR_RE.search(text or "")
    if not m:
        return None
    if m.group(3):
        return 0
    return int(m.group(1) or m.group(2))


def sniff_elevator(text: str) -> bool | None:
    t = (text or "").lower()
    if re.search(r"sans\s+ascenseur|pas\s+d.ascenseur", t):
        return False
    if "ascenseur" in t:
        return True
    return None


# --------------------------------------------------------------------------- #
# Adapters
# --------------------------------------------------------------------------- #

def build_listing(source: str, item: dict, mapping: dict, base_url: str = "") -> Listing:
    def g(key):
        p = mapping.get(key)
        if not p:
            return None
        if isinstance(p, list):                  # try several paths in order
            for cand in p:
                v = dig(item, cand)
                if v not in (None, ""):
                    return v
            return None
        return dig(item, p)

    url = str(g("url") or "")
    if url and base_url and url.startswith("/"):
        url = base_url.rstrip("/") + url

    text_parts = [str(g("title") or ""), str(g("description") or "")]
    raw_text = " ".join(p for p in text_parts if p)

    floor = to_int(g("floor"))
    if floor is None:
        floor = sniff_floor(raw_text)

    elevator = g("elevator")
    if isinstance(elevator, str):
        elevator = elevator.strip().lower() in ("1", "true", "oui", "yes")
    if elevator is None:
        elevator = sniff_elevator(raw_text)

    return Listing(
        source=source,
        ref=str(g("ref") or ""),
        title=str(g("title") or "")[:180],
        url=url,
        commune=str(g("commune") or ""),
        cp=str(g("cp") or ""),
        rent=to_num(g("rent")),
        surface=to_num(g("surface")),
        rooms=to_int(g("rooms")),
        floor=floor,
        elevator=elevator,
        raw_text=raw_text,
        raw=item,
    )


def fetch_json(cfg: dict, session: requests.Session, dump: bool = False) -> list[Listing]:
    """Hit the site's own XHR/JSON endpoint. Fastest and most stable when available."""
    method = cfg.get("method", "GET").upper()
    resp = session.request(
        method,
        cfg["url"],
        params=cfg.get("params"),
        json=cfg.get("body") if method != "GET" else None,
        headers={**{"User-Agent": UA, "Accept": "application/json"}, **cfg.get("headers", {})},
        timeout=25,
    )
    resp.raise_for_status()
    data = resp.json()

    items = dig(data, cfg.get("items_path", "")) if cfg.get("items_path") else data
    if not isinstance(items, list):
        items = []

    if dump:
        print(f"--- {cfg['name']}: {len(items)} item(s) ---")
        print(json.dumps(items[0] if items else data, indent=2, ensure_ascii=False)[:6000])
        return []

    mapping = cfg.get("map", {})
    return [build_listing(cfg["name"], it, mapping, cfg.get("base_url", "")) for it in items]


def fetch_html(cfg: dict, session: requests.Session, dump: bool = False) -> list[Listing]:
    """CSS-selector scraping. Only works if the listings are server-rendered."""
    resp = session.get(
        cfg["url"],
        params=cfg.get("params"),
        headers={**{"User-Agent": UA}, **cfg.get("headers", {})},
        timeout=25,
    )
    resp.raise_for_status()
    soup = BeautifulSoup(resp.text, "html.parser")
    cards = soup.select(cfg["item_selector"])

    if dump:
        print(f"--- {cfg['name']}: {len(cards)} card(s) ---")
        print(cards[0].prettify()[:6000] if cards else resp.text[:3000])
        return []

    sel = cfg.get("select", {})
    out = []
    for card in cards:
        item = {}
        for key, css in sel.items():
            attr = None
            if "@" in css:
                css, attr = css.split("@", 1)
            node = card.select_one(css.strip()) if css.strip() else card
            if node is None:
                continue
            item[key] = node.get(attr) if attr else node.get_text(" ", strip=True)
        mapping = {k: k for k in item}
        out.append(build_listing(cfg["name"], item, mapping, cfg.get("base_url", "")))
    return out


ADAPTERS = {"json": fetch_json, "html": fetch_html}


# --------------------------------------------------------------------------- #
# State + notification
# --------------------------------------------------------------------------- #

def load_state() -> set[str]:
    if STATE_PATH.exists():
        return set(json.loads(STATE_PATH.read_text()).get("seen", []))
    return set()


def save_state(seen: set[str]) -> None:
    STATE_PATH.write_text(json.dumps({"seen": sorted(seen)}, indent=0))


def notify(listings: list[Listing], cfg: dict) -> None:
    body = "\n\n".join(l.line() for l in listings)
    subject = f"[scout] {len(listings)} nouvelle(s) annonce(s)"

    print(f"\n=== {subject} ===\n{body}\n")

    ntfy_topic = cfg.get("ntfy_topic") or os.getenv("SCOUT_NTFY_TOPIC")
    if ntfy_topic:
        try:
            requests.post(
                f"https://ntfy.sh/{ntfy_topic}",
                data=body.encode("utf-8"),
                headers={"Title": subject, "Priority": "high"},
                timeout=10,
            )
        except Exception as e:  # noqa: BLE001
            print(f"[warn] ntfy failed: {e}", file=sys.stderr)

    mail = cfg.get("email") or {}
    if mail.get("to") and os.getenv("SCOUT_SMTP_PASS"):
        try:
            msg = EmailMessage()
            msg["Subject"] = subject
            msg["From"] = mail["from"]
            msg["To"] = mail["to"]
            msg.set_content(body)
            with smtplib.SMTP_SSL(mail["host"], mail.get("port", 465)) as s:
                s.login(mail["from"], os.environ["SCOUT_SMTP_PASS"])
                s.send_message(msg)
        except Exception as e:  # noqa: BLE001
            print(f"[warn] smtp failed: {e}", file=sys.stderr)


# --------------------------------------------------------------------------- #
# Run
# --------------------------------------------------------------------------- #

def run_once(conf: dict, use_state: bool = True, only: str | None = None,
             dump: bool = False, verbose: bool = False) -> None:
    crit = Criteria(**conf.get("criteria", {}))
    seen = load_state() if use_state else set()
    session = requests.Session()
    fresh: list[Listing] = []

    for src in conf["sources"]:
        if not src.get("enabled", True):
            continue
        if only and src["name"] != only:
            continue

        adapter = ADAPTERS.get(src.get("type", "json"))
        if adapter is None:
            print(f"[warn] unknown type for {src['name']}", file=sys.stderr)
            continue

        try:
            listings = adapter(src, session, dump=dump)
        except Exception as e:  # noqa: BLE001
            print(f"[error] {src['name']}: {type(e).__name__}: {e}", file=sys.stderr)
            continue

        if dump:
            continue

        kept = 0
        for l in listings:
            ok, why = crit.matches(l)
            if not ok:
                if verbose:
                    print(f"  skip ({why}): {l.commune} {l.title[:60]}")
                continue
            if l.uid in seen:
                continue
            seen.add(l.uid)
            fresh.append(l)
            kept += 1
        print(f"[{src['name']}] {len(listings)} annonces, {kept} nouvelle(s) retenue(s)")

        time.sleep(random.uniform(1.5, 4.0))   # be polite

    if dump:
        return

    if fresh:
        notify(fresh, conf.get("notify", {}))
    else:
        print("Rien de nouveau.")

    if use_state:
        save_state(seen)


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--config", default=str(CONFIG_PATH))
    ap.add_argument("--once", action="store_true")
    ap.add_argument("--watch", action="store_true")
    ap.add_argument("--dump", metavar="SOURCE", help="print raw payload for one source")
    ap.add_argument("--only", metavar="SOURCE", help="run a single source")
    ap.add_argument("--no-state", action="store_true")
    ap.add_argument("--verbose", "-v", action="store_true")
    ap.add_argument("--interval", type=int, default=1800, help="seconds between passes")
    args = ap.parse_args()

    conf = yaml.safe_load(Path(args.config).read_text(encoding="utf-8"))

    if args.dump:
        run_once(conf, use_state=False, only=args.dump, dump=True)
        return

    if args.watch:
        while True:
            run_once(conf, use_state=not args.no_state, only=args.only, verbose=args.verbose)
            nap = args.interval + random.randint(-180, 180)
            print(f"-- sleep {nap}s --")
            time.sleep(max(60, nap))
    else:
        run_once(conf, use_state=not args.no_state, only=args.only, verbose=args.verbose)


if __name__ == "__main__":
    main()
