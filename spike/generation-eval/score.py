#!/usr/bin/env python3
"""Score the generation spike against the doc 08 generation eval targets.

Reads <out>/runs.csv (written by run_eval.py) and <out>/human-review.csv
(filled in by a reviewer), prints a per-provider table and writes
<out>/summary.md.

human-review.csv columns, per (recording, provider):
  human_step_count          number of distinct actions a person counts in the recording
  steps_with_correct_frame  how many of the draft's frames show the right moment
  verdict                   approve-light | approve-heavy | rewrite | halted-correctly | halted-wrongly
  edited_draft_path         optional: path to the draft.md after the reviewer edited it
                            to an approvable state (edit ratio is computed from it)
  notes                     free text

Doc 08 targets:
  draft produced without failure           100% (except the deliberate no-audio case)
  steps in draft vs human count            within ±20%
  steps with screenshot from correct range > 90%
  median edit distance draft → approved    tracked, must trend down
  time to draft, 15-min recording          < 5 min p90
"""
from __future__ import annotations

import argparse
import csv
import difflib
import statistics
import sys
from collections import defaultdict
from pathlib import Path


def pct(n: float, d: float) -> str:
    return "—" if not d else f"{100 * n / d:.0f}%"


def p90(xs: list[float]) -> float | None:
    if not xs:
        return None
    xs = sorted(xs)
    return xs[min(len(xs) - 1, int(round(0.9 * (len(xs) - 1))))]


def edit_ratio(draft: Path, edited: Path) -> float | None:
    if not draft.exists() or not edited.exists():
        return None
    a, b = draft.read_text().split(), edited.read_text().split()
    return round(1 - difflib.SequenceMatcher(None, a, b).ratio(), 3)


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--out", required=True, type=Path, help="the run_eval.py --out directory")
    a = ap.parse_args()

    runs_path, rev_path = a.out / "runs.csv", a.out / "human-review.csv"
    if not runs_path.exists():
        print(f"{runs_path} not found — run run_eval.py first", file=sys.stderr)
        return 2
    runs = {(r["recording"], r["provider"]): r for r in csv.DictReader(runs_path.open())}
    reviews = {}
    if rev_path.exists():
        reviews = {(r["recording"], r["provider"]): r for r in csv.DictReader(rev_path.open())}

    by_prov: dict[str, dict] = defaultdict(lambda: {
        "runs": 0, "drafts": 0, "halted": 0, "errors": 0, "reviewed": 0,
        "step_within_20": 0, "frames_ok": 0, "frames_total": 0,
        "verdicts": defaultdict(int), "edit_ratios": [], "time_to_draft_15": [],
        "transcribe_sec_per_min": [], "cost": [], "conf": [],
    })
    rows_md = []
    for (rec, prov), r in runs.items():
        s = by_prov[prov]
        s["runs"] += 1
        halted = r.get("halted", "").lower() == "true"
        if (r.get("halt_reason") or "").startswith("error:"):
            s["errors"] += 1
        elif halted:
            s["halted"] += 1
        else:
            s["drafts"] += 1
        dur = float(r["duration_sec"] or 0)
        if dur:
            s["transcribe_sec_per_min"].append(float(r["transcribe_sec"] or 0) / (dur / 60))
            # normalise time-to-draft to a 15-minute recording (roughly linear in audio length)
            s["time_to_draft_15"].append(float(r["total_sec"] or 0) * (900 / dur))
        if r.get("total_cost_usd"):
            s["cost"].append(float(r["total_cost_usd"]))
        if r.get("mean_conf"):
            s["conf"].append(float(r["mean_conf"]))

        rv = reviews.get((rec, prov), {})
        steps = int(r.get("steps") or 0)
        human = rv.get("human_step_count")
        within = ""
        if human:
            s["reviewed"] += 1
            h = int(human)
            ok = h and abs(steps - h) / h <= 0.20
            s["step_within_20"] += int(bool(ok))
            within = "✓" if ok else "✗"
        if rv.get("steps_with_correct_frame") and steps:
            s["frames_ok"] += int(rv["steps_with_correct_frame"])
            s["frames_total"] += steps
        if rv.get("verdict"):
            s["verdicts"][rv["verdict"]] += 1
        er = None
        if rv.get("edited_draft_path"):
            er = edit_ratio(a.out / rec / prov / "draft.md", Path(rv["edited_draft_path"]))
            if er is not None:
                s["edit_ratios"].append(er)
        rows_md.append(f"| {rec} | {prov} | {'halted' if halted else steps} | {human or ''} {within} | "
                       f"{rv.get('steps_with_correct_frame', '')} | {rv.get('verdict', '')} | "
                       f"{'' if er is None else er} | {r.get('total_sec', '')} | {r.get('total_cost_usd', '')} |")

    lines = ["# Generation spike — summary", "",
             "Targets from 08-QA-and-Test-Plan §4 (generation eval set).", "",
             "| Metric | Target | " + " | ".join(by_prov) + " |",
             "|---|---|" + "---|" * len(by_prov)]

    def row(label, target, fn):
        lines.append(f"| {label} | {target} | " + " | ".join(fn(by_prov[p]) for p in by_prov) + " |")

    row("Runs", "", lambda s: str(s["runs"]))
    row("Draft produced", "100% (bar the no-audio case)", lambda s: pct(s["drafts"], s["runs"]))
    row("Halted (FR-314)", "only the no-audio case", lambda s: str(s["halted"]))
    row("Errors", "0", lambda s: str(s["errors"]))
    row("Mean transcript confidence", "informational", lambda s: f"{statistics.mean(s['conf']):.2f}" if s["conf"] else "—")
    row("Transcribe seconds per audio minute", "informational", lambda s: f"{statistics.mean(s['transcribe_sec_per_min']):.1f}" if s["transcribe_sec_per_min"] else "—")
    row("Time to draft, 15-min recording, p90", "< 300 s", lambda s: f"{p90(s['time_to_draft_15']):.0f} s" if s["time_to_draft_15"] else "—")
    row("Cost per draft (avg)", "informational", lambda s: f"${statistics.mean(s['cost']):.3f}" if s["cost"] else "—")
    row("Reviewed", "10", lambda s: str(s["reviewed"]))
    row("Step count within ±20% of human", "100%", lambda s: pct(s["step_within_20"], s["reviewed"]))
    row("Frames from correct time range", "> 90%", lambda s: pct(s["frames_ok"], s["frames_total"]))
    row("Verdict: approve after light edits", "the M1 gate", lambda s: pct(s["verdicts"].get("approve-light", 0), sum(s["verdicts"].values())))
    row("Verdict: rewrite", "0", lambda s: str(s["verdicts"].get("rewrite", 0)))
    row("Median edit ratio (0 = untouched)", "track; must trend down", lambda s: f"{statistics.median(s['edit_ratios']):.2f}" if s["edit_ratios"] else "—")

    lines += ["", "## Per run", "",
              "| Recording | Provider | Steps | Human ±20% | Frames ok | Verdict | Edit ratio | Seconds | USD |",
              "|---|---|---|---|---|---|---|---|---|"] + rows_md
    unreviewed = [k for k in runs if not reviews.get(k, {}).get("verdict")]
    if unreviewed:
        lines += ["", f"_{len(unreviewed)} run(s) not yet reviewed — verdict, step count and frame "
                      f"columns are incomplete until human-review.csv is filled in._"]
    text = "\n".join(lines) + "\n"
    (a.out / "summary.md").write_text(text)
    print(text)
    return 0


if __name__ == "__main__":
    sys.exit(main())
