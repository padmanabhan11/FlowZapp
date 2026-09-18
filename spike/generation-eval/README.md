# Generation spike — recording → draft SOP

A one-week, throwaway evaluation that answers two questions before M0 starts:

1. **Which transcription provider?** (backlog story D0 — blocks the wedge)
2. **Do generated drafts get approved after light edits, or rewritten?** (the M1 gate in 07-Roadmap)

It is not product code. It runs each recording through Whisper and Deepgram, asks Claude for a draft in the F1 structure from 02-Functional-Specification, pulls one frame per step, and scores the result against the generation targets in 08-QA-and-Test-Plan §4.

## Setup

```bash
brew install ffmpeg                      # macOS; apt install ffmpeg on Linux
cd spike/generation-eval
python3 -m venv .venv && source .venv/bin/activate
pip install -r requirements.txt
cp .env.example .env                     # then fill in the three keys
```

## Recordings

Put ten real recordings in `recordings/` (`.webm`, `.mp4`, `.mov`). Cover the range doc 08 asks for: short (~3 min) and long (~45 min), clear and messy narration, click-heavy and talk-heavy, accented English, and **one with almost no usable audio** — that one is supposed to halt, not produce a draft (FR-314).

Record them the way a real contributor would: screen + microphone, narrating while doing the task. Do not script them.

## Run

```bash
python run_eval.py --recordings ./recordings --out ./out            # both providers
python run_eval.py --recordings ./recordings --out ./out --mock     # pipeline check, no API calls
python run_eval.py --recordings ./recordings --out ./out --providers deepgram --limit 2
```

Runs are resumable; re-run the same command and finished pairs are skipped (`--force` redoes them). Output per recording and provider:

```
out/<recording>/<provider>/
  transcript.json    words with timestamps and confidence
  draft.json         the structured draft (F1 shape)
  draft.md           the same draft, readable, with frame links
  frames/step-NN.jpg one frame per step, at the midpoint of the step's time range
  metrics.json       timings, tokens, cost, validation problems
out/runs.csv         one row per run
out/human-review.csv one blank row per run for the reviewer
```

## Review

For each run open `draft.md` next to the recording and fill in the row in `human-review.csv`:

| column | what to enter |
|---|---|
| `human_step_count` | how many distinct actions *you* count in the recording (count once per recording, reuse for both providers) |
| `steps_with_correct_frame` | how many of the draft's frames show the right moment |
| `verdict` | `approve-light` (you'd approve after small edits), `approve-heavy`, `rewrite`, `halted-correctly`, `halted-wrongly` |
| `edited_draft_path` | optional: save your edited `draft.md` somewhere and point here — the scorer computes an edit ratio |
| `notes` | anything the numbers miss: hallucinated steps, merged steps, wrong order |

Be honest with `verdict`. The whole point is to find out *now* whether the premise holds.

## Score

```bash
python score.py --out ./out
```

Prints a per-provider table and writes `out/summary.md`. Read it against the targets:

| metric | target |
|---|---|
| draft produced without failure | 100% (except the no-audio case, which must halt cleanly) |
| step count within ±20% of the human count | 100% |
| frames from the correct time range | > 90% |
| verdict `approve-light` | this is the M1 gate — if most drafts are `rewrite`, stop and fix generation before building anything else |
| time to draft, 15-min recording | < 5 min p90 |
| cost per draft | informational — feeds the margin check (BR-32) |

## Deciding

**Provider (D0):** pick on word-timestamp accuracy (check a few frames against the video), behaviour on the accented and noisy recordings, and cost per minute. Both must contractually exclude customer content from training before either is used on customer recordings (BRD D5).

**The wedge (M1 gate):** if drafts are `approve-light` for most recordings, proceed to M0 with confidence. If not, iterate `prompts.py` — segmenting the transcript per action before generation, sending the low-confidence spans explicitly, or trying a different model — and re-run. Keep every prompt version; the recordings and reviews become the permanent generation eval set that doc 08 requires before any prompt or model change.

## Files

- `run_eval.py` — pipeline (ffmpeg → transcription → Claude → frames → CSVs)
- `score.py` — turns runs + reviews into the doc 08 metrics
- `prompts.py` — the generation system prompt and output schema; iterate here
- `requirements.txt`, `.env.example`
