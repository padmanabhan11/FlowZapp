#!/usr/bin/env python3
"""FlowZapp generation spike — recording → transcript → draft SOP → frames.

For every recording in --recordings, and for every transcription provider in
--providers, this script:

  1. extracts a mono MP3 with ffmpeg,
  2. transcribes it with word-level timestamps (Whisper API or Deepgram),
  3. asks Claude for an F1-structured draft SOP (see prompts.py),
  4. extracts one frame per step at the midpoint of the step's time range,
  5. writes transcript.json, draft.json, draft.md, frames/, metrics.json,

and appends one row per (recording, provider) to <out>/runs.csv plus a blank
row to <out>/human-review.csv for the reviewer to fill in. score.py then turns
both files into the doc 08 generation metrics.

Nothing here is product code. It exists to answer two questions before M0:
which transcription provider, and do drafts get light edits or rewrites.

Usage:
  python run_eval.py --recordings ./recordings --out ./out
  python run_eval.py --recordings ./recordings --out ./out --providers deepgram
  python run_eval.py --recordings ./recordings --out ./out --mock   # no API calls

Environment (or a .env file next to this script):
  OPENAI_API_KEY, DEEPGRAM_API_KEY, ANTHROPIC_API_KEY
  CLAUDE_MODEL (default claude-sonnet-4-5)
"""
from __future__ import annotations

import argparse
import csv
import json
import os
import re
import shutil
import subprocess
import sys
import time
from dataclasses import dataclass, asdict, field
from pathlib import Path

import prompts

VIDEO_EXT = {".webm", ".mp4", ".mov", ".mkv", ".m4v"}

# Price assumptions — edit to match your contracts. USD.
PRICE = {
    "whisper_per_min": 0.006,
    "deepgram_per_min": 0.0077,          # nova-3, pay-as-you-go
    "claude_in_per_mtok": 3.00,          # sonnet 4.5
    "claude_out_per_mtok": 15.00,
}

HALT_CONFIDENCE = 0.55   # mirrors FR-314: halt rather than fabricate


# --------------------------------------------------------------------------- #
# helpers
# --------------------------------------------------------------------------- #
def load_dotenv(path: Path) -> None:
    if not path.exists():
        return
    for line in path.read_text().splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        k, v = line.split("=", 1)
        os.environ.setdefault(k.strip(), v.strip().strip('"').strip("'"))


def sh(cmd: list[str]) -> str:
    r = subprocess.run(cmd, capture_output=True, text=True)
    if r.returncode != 0:
        raise RuntimeError(f"{' '.join(cmd[:3])}… failed:\n{r.stderr[-2000:]}")
    return r.stdout


def probe_duration(video: Path) -> float:
    out = sh(["ffprobe", "-v", "error", "-show_entries", "format=duration",
              "-of", "default=noprint_wrappers=1:nokey=1", str(video)])
    return float(out.strip())


def extract_audio(video: Path, mp3: Path) -> None:
    if mp3.exists():
        return
    # 48 kbps mono keeps a 60-minute recording under Whisper's 25 MB cap.
    sh(["ffmpeg", "-y", "-v", "error", "-i", str(video), "-vn", "-ac", "1",
        "-ar", "16000", "-b:a", "48k", str(mp3)])


def extract_frame(video: Path, ts: float, jpg: Path) -> None:
    sh(["ffmpeg", "-y", "-v", "error", "-ss", f"{max(ts, 0):.3f}", "-i", str(video),
        "-frames:v", "1", "-q:v", "3", str(jpg)])


@dataclass
class Transcript:
    provider: str
    words: list[dict]            # [{w, start, end, conf}]
    full_text: str
    language: str | None
    mean_conf: float | None
    seconds: float               # wall time
    cost_usd: float

    def lines(self, window: float = 10.0) -> str:
        """Group words into ~window-second lines: '[12.4–21.9] words…'."""
        if not self.words:
            return ""
        out, buf, start = [], [], self.words[0]["start"]
        for w in self.words:
            if buf and w["start"] - start >= window:
                out.append(f"[{start:.1f}–{buf[-1]['end']:.1f}] " + " ".join(x["w"] for x in buf))
                buf, start = [], w["start"]
            buf.append(w)
        if buf:
            out.append(f"[{start:.1f}–{buf[-1]['end']:.1f}] " + " ".join(x["w"] for x in buf))
        return "\n".join(out)


# --------------------------------------------------------------------------- #
# transcription providers
# --------------------------------------------------------------------------- #
def transcribe_whisper(mp3: Path, duration: float) -> Transcript:
    from openai import OpenAI  # pip install openai
    client = OpenAI()
    t0 = time.time()
    with mp3.open("rb") as f:
        r = client.audio.transcriptions.create(
            model="whisper-1", file=f, response_format="verbose_json",
            timestamp_granularities=["word", "segment"])
    words = [{"w": w.word, "start": float(w.start), "end": float(w.end), "conf": None}
             for w in (r.words or [])]
    # Whisper gives no per-word confidence; use mean segment avg_logprob as a proxy.
    segs = getattr(r, "segments", None) or []
    conf = None
    if segs:
        import math
        conf = sum(math.exp(getattr(s, "avg_logprob", -1.0)) for s in segs) / len(segs)
    return Transcript("whisper", words, r.text, getattr(r, "language", None), conf,
                      time.time() - t0, duration / 60 * PRICE["whisper_per_min"])


def transcribe_deepgram(mp3: Path, duration: float) -> Transcript:
    import requests  # pip install requests
    key = os.environ["DEEPGRAM_API_KEY"]
    t0 = time.time()
    with mp3.open("rb") as f:
        r = requests.post(
            "https://api.deepgram.com/v1/listen",
            params={"model": "nova-3", "smart_format": "true", "punctuate": "true",
                    "detect_language": "true"},
            headers={"Authorization": f"Token {key}", "Content-Type": "audio/mpeg"},
            data=f, timeout=900)
    r.raise_for_status()
    j = r.json()
    alt = j["results"]["channels"][0]["alternatives"][0]
    words = [{"w": w.get("punctuated_word", w["word"]), "start": float(w["start"]),
              "end": float(w["end"]), "conf": float(w.get("confidence", 0))}
             for w in alt.get("words", [])]
    conf = (sum(w["conf"] for w in words) / len(words)) if words else 0.0
    lang = j["results"]["channels"][0].get("detected_language")
    return Transcript("deepgram", words, alt.get("transcript", ""), lang, conf,
                      time.time() - t0, duration / 60 * PRICE["deepgram_per_min"])


def transcribe_mock(mp3: Path, duration: float) -> Transcript:
    text = ("open the client folder and duplicate the intake template then rename it "
            "with the client name set the delivery date in the tracker and finally "
            "send the welcome email from the shared inbox").split()
    step = max(duration, 60) / len(text)
    words = [{"w": w, "start": i * step, "end": (i + 1) * step, "conf": 0.9}
             for i, w in enumerate(text)]
    return Transcript("mock", words, " ".join(text), "en", 0.9, 0.01, 0.0)


PROVIDERS = {"whisper": transcribe_whisper, "deepgram": transcribe_deepgram, "mock": transcribe_mock}


# --------------------------------------------------------------------------- #
# generation
# --------------------------------------------------------------------------- #
@dataclass
class Generation:
    model: str
    draft: dict | None
    halted: bool
    halt_reason: str | None
    seconds: float
    input_tokens: int
    output_tokens: int
    cost_usd: float
    raw: str = ""


def _parse_json(text: str) -> dict:
    text = text.strip()
    m = re.search(r"\{.*\}", text, re.S)
    if not m:
        raise ValueError("no JSON object in model output")
    return json.loads(m.group(0))


def validate_draft(d: dict) -> list[str]:
    problems = []
    for k in ("title", "purpose", "scope", "prerequisites", "steps", "outcome"):
        if k not in d:
            problems.append(f"missing {k}")
    steps = d.get("steps") or []
    if len(steps) < 2:
        problems.append("fewer than 2 steps")
    for i, s in enumerate(steps, 1):
        for k in ("instruction", "ts_start", "ts_end"):
            if k not in s:
                problems.append(f"step {i} missing {k}")
        if "ts_start" in s and "ts_end" in s and s["ts_end"] < s["ts_start"]:
            problems.append(f"step {i} ts_end < ts_start")
    return problems


def generate_claude(title: str, duration: float, tr: Transcript, model: str) -> Generation:
    import anthropic  # pip install anthropic
    client = anthropic.Anthropic()
    user = prompts.USER_TEMPLATE.format(title=title, duration_sec=duration,
                                        transcript_lines=tr.lines())
    t0 = time.time()
    msg = client.messages.create(model=model, max_tokens=4096, temperature=0,
                                 system=prompts.SYSTEM,
                                 messages=[{"role": "user", "content": user}])
    text = "".join(b.text for b in msg.content if getattr(b, "type", "") == "text")
    secs = time.time() - t0
    it, ot = msg.usage.input_tokens, msg.usage.output_tokens
    cost = it / 1e6 * PRICE["claude_in_per_mtok"] + ot / 1e6 * PRICE["claude_out_per_mtok"]
    try:
        d = _parse_json(text)
    except Exception as e:  # noqa: BLE001
        return Generation(model, None, True, f"unparseable output: {e}", secs, it, ot, cost, text)
    if d.get("halt"):
        return Generation(model, None, True, d.get("reason", "model halted"), secs, it, ot, cost, text)
    return Generation(model, d, False, None, secs, it, ot, cost, text)


def generate_mock(title: str, duration: float, tr: Transcript, model: str) -> Generation:
    n = 5
    span = duration / n
    steps = [{"n": i + 1, "instruction": f"Mock step {i + 1}", "ts_start": i * span,
              "ts_end": (i + 1) * span, "warning": None, "expected_result": None,
              "note": None, "confidence": 0.8} for i in range(n)]
    d = {"title": title, "purpose": "Mock purpose.", "scope": "Mock scope.",
         "prerequisites": ["Mock access"], "steps": steps, "outcome": "Mock outcome."}
    return Generation("mock", d, False, None, 0.01, 0, 0, 0.0, json.dumps(d))


def draft_to_markdown(d: dict, rec: str, provider: str) -> str:
    lines = [f"# {d.get('title', rec)}", "",
             f"_Draft generated from `{rec}` via {provider}. Not approved._", "",
             "## Purpose", d.get("purpose", ""), "",
             "## Scope", d.get("scope", ""), "",
             "## Prerequisites"]
    lines += [f"- {p}" for p in d.get("prerequisites", [])] or ["- (none)"]
    lines += ["", "## Steps"]
    for s in d.get("steps", []):
        lines.append(f"{s.get('n', '?')}. {s['instruction']}  \n"
                     f"   ⏱ {s['ts_start']:.1f}–{s['ts_end']:.1f}s · confidence {s.get('confidence', '?')}")
        if s.get("warning"):
            lines.append(f"   > ⚠ {s['warning']}")
        if s.get("expected_result"):
            lines.append(f"   > Expected: {s['expected_result']}")
        if s.get("note"):
            lines.append(f"   > Note: {s['note']}")
        lines.append(f"   ![step {s.get('n')}](frames/step-{s.get('n'):02d}.jpg)")
    lines += ["", "## Outcome", d.get("outcome", "")]
    return "\n".join(lines) + "\n"


# --------------------------------------------------------------------------- #
# main loop
# --------------------------------------------------------------------------- #
RUN_COLS = ["recording", "provider", "model", "duration_sec", "transcribe_sec", "mean_conf",
            "language", "generate_sec", "total_sec", "halted", "halt_reason", "steps",
            "low_conf_steps", "frames", "input_tokens", "output_tokens",
            "transcribe_cost_usd", "generate_cost_usd", "total_cost_usd", "validation"]
REVIEW_COLS = ["recording", "provider", "human_step_count", "steps_with_correct_frame",
               "verdict", "edited_draft_path", "notes"]


def append_csv(path: Path, cols: list[str], row: dict) -> None:
    new = not path.exists()
    with path.open("a", newline="") as f:
        w = csv.DictWriter(f, fieldnames=cols)
        if new:
            w.writeheader()
        w.writerow({c: row.get(c, "") for c in cols})


def run_one(video: Path, provider: str, out: Path, model: str, mock: bool, force: bool) -> None:
    rec = video.stem
    rdir = out / rec / provider
    if (rdir / "metrics.json").exists() and not force:
        print(f"  {rec} / {provider}: done, skipping (use --force to redo)")
        return
    rdir.mkdir(parents=True, exist_ok=True)
    (rdir / "frames").mkdir(exist_ok=True)

    duration = probe_duration(video)
    mp3 = out / rec / "audio.mp3"
    extract_audio(video, mp3)

    t_all = time.time()
    print(f"  {rec} / {provider}: transcribing ({duration / 60:.1f} min)…", flush=True)
    tr = (transcribe_mock if mock else PROVIDERS[provider])(mp3, duration)
    (rdir / "transcript.json").write_text(json.dumps(asdict(tr), indent=1))

    gen: Generation
    if tr.mean_conf is not None and tr.mean_conf < HALT_CONFIDENCE:
        gen = Generation(model, None, True,
                         f"transcript confidence {tr.mean_conf:.2f} below {HALT_CONFIDENCE}",
                         0, 0, 0, 0.0)
        print(f"    halted: {gen.halt_reason}")
    else:
        print("    generating draft…", flush=True)
        gen = (generate_mock if mock else generate_claude)(rec, duration, tr, model)

    validation = []
    frames = 0
    low = 0
    if gen.draft:
        validation = validate_draft(gen.draft)
        (rdir / "draft.json").write_text(json.dumps(gen.draft, indent=1))
        for s in gen.draft.get("steps", []):
            mid = (float(s["ts_start"]) + float(s["ts_end"])) / 2
            jpg = rdir / "frames" / f"step-{int(s.get('n', frames + 1)):02d}.jpg"
            try:
                extract_frame(video, min(mid, max(duration - 0.5, 0)), jpg)
                frames += 1
            except RuntimeError as e:
                print(f"    frame for step {s.get('n')} failed: {e}")
            if (s.get("confidence") or 1) < 0.6:
                low += 1
        (rdir / "draft.md").write_text(draft_to_markdown(gen.draft, rec, provider))
    else:
        (rdir / "draft.md").write_text(f"# HALTED\n\n{gen.halt_reason}\n")
    (rdir / "model_output.txt").write_text(gen.raw)

    total = time.time() - t_all
    metrics = {
        "recording": rec, "provider": tr.provider, "model": gen.model,
        "duration_sec": round(duration, 1), "transcribe_sec": round(tr.seconds, 1),
        "mean_conf": None if tr.mean_conf is None else round(tr.mean_conf, 3),
        "language": tr.language, "generate_sec": round(gen.seconds, 1),
        "total_sec": round(total, 1), "halted": gen.halted, "halt_reason": gen.halt_reason,
        "steps": len(gen.draft.get("steps", [])) if gen.draft else 0,
        "low_conf_steps": low, "frames": frames,
        "input_tokens": gen.input_tokens, "output_tokens": gen.output_tokens,
        "transcribe_cost_usd": round(tr.cost_usd, 4), "generate_cost_usd": round(gen.cost_usd, 4),
        "total_cost_usd": round(tr.cost_usd + gen.cost_usd, 4),
        "validation": "; ".join(validation),
    }
    (rdir / "metrics.json").write_text(json.dumps(metrics, indent=1))
    append_csv(out / "runs.csv", RUN_COLS, metrics)
    append_csv(out / "human-review.csv", REVIEW_COLS,
               {"recording": rec, "provider": tr.provider})
    print(f"    {metrics['steps']} steps, {frames} frames, {total:.0f}s, ${metrics['total_cost_usd']:.3f}"
          + (f"  ⚠ {metrics['validation']}" if validation else ""))


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--recordings", required=True, type=Path)
    ap.add_argument("--out", required=True, type=Path)
    ap.add_argument("--providers", default="whisper,deepgram",
                    help="comma-separated: whisper, deepgram")
    ap.add_argument("--model", default=os.environ.get("CLAUDE_MODEL", "claude-sonnet-4-5"))
    ap.add_argument("--limit", type=int, default=0, help="only the first N recordings")
    ap.add_argument("--force", action="store_true", help="redo runs that already exist")
    ap.add_argument("--mock", action="store_true", help="no API calls; exercise the pipeline only")
    a = ap.parse_args()

    load_dotenv(Path(__file__).with_name(".env"))
    for tool in ("ffmpeg", "ffprobe"):
        if not shutil.which(tool):
            print(f"{tool} not found on PATH (brew install ffmpeg)", file=sys.stderr)
            return 2

    providers = ["mock"] if a.mock else [p.strip() for p in a.providers.split(",") if p.strip()]
    for p in providers:
        if p not in PROVIDERS:
            print(f"unknown provider {p}", file=sys.stderr)
            return 2
    if not a.mock:
        need = {"whisper": "OPENAI_API_KEY", "deepgram": "DEEPGRAM_API_KEY"}
        missing = [need[p] for p in providers if need.get(p) and not os.environ.get(need[p])]
        if not os.environ.get("ANTHROPIC_API_KEY"):
            missing.append("ANTHROPIC_API_KEY")
        if missing:
            print("missing environment variables: " + ", ".join(missing), file=sys.stderr)
            return 2

    videos = sorted(p for p in a.recordings.iterdir() if p.suffix.lower() in VIDEO_EXT)
    if a.limit:
        videos = videos[: a.limit]
    if not videos:
        print(f"no recordings ({', '.join(sorted(VIDEO_EXT))}) in {a.recordings}", file=sys.stderr)
        return 2
    a.out.mkdir(parents=True, exist_ok=True)
    print(f"{len(videos)} recording(s) × {providers} → {a.out}")
    for v in videos:
        for p in providers:
            try:
                run_one(v, p, a.out, a.model, a.mock, a.force)
            except Exception as e:  # noqa: BLE001 — one failure must not stop the batch
                print(f"  {v.stem} / {p}: FAILED — {e}", file=sys.stderr)
                append_csv(a.out / "runs.csv", RUN_COLS,
                           {"recording": v.stem, "provider": p, "halted": True,
                            "halt_reason": f"error: {e}"})
    print(f"\nDone. Next: open {a.out / 'human-review.csv'}, review each draft.md against its "
          f"recording, then run score.py.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
