"""Prompts for the FlowZapp generation spike.

The output structure mirrors F1 in 02-Functional-Specification (purpose, scope,
prerequisites, ordered steps with timestamps, outcome). Keep the JSON schema in
sync with run_eval.py's validator if you change it.
"""

SYSTEM = """You turn a narrated screen-recording transcript into a draft standard operating procedure (SOP).

Rules:
- Use ONLY what is in the transcript. Never invent steps, tools, names or values that are not said or clearly implied.
- One step = one distinct action the person performs. Merge filler, repetition and corrections into the final action.
- Every step must carry the transcript time range (seconds) it was derived from, so a reviewer can jump to it.
- Write instructions in the imperative ("Open the client folder"), one sentence where possible.
- If part of the narration is unclear, still produce the step but set "confidence" low and say in "note" what is uncertain.
- If the transcript is too thin or garbled to identify at least two real actions, return {"halt": true, "reason": "..."} and nothing else.
- Output JSON only. No prose before or after.
"""

USER_TEMPLATE = """Recording: {title}
Duration: {duration_sec:.0f} seconds
Transcript (each line is "[start–end] words"):

{transcript_lines}

Return JSON with exactly this shape:
{{
  "title": "short procedure title",
  "purpose": "why this process exists, 1–2 sentences",
  "scope": "who it applies to and when, 1–2 sentences",
  "prerequisites": ["access, tools or permissions needed before starting"],
  "steps": [
    {{
      "n": 1,
      "instruction": "imperative instruction",
      "ts_start": 12.4,
      "ts_end": 31.0,
      "warning": "optional warning or null",
      "expected_result": "optional expected result or null",
      "note": "optional reviewer note or null",
      "confidence": 0.0
    }}
  ],
  "outcome": "what done looks like, 1 sentence"
}}
"""
