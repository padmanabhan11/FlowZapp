# Retrieval eval set

Doc 08 "Retrieval eval set": **50 question/answer pairs built from design-partner content, plus 15 deliberately unanswerable questions.** Prompt, model, chunking and fusion changes do not ship without re-running it. This folder holds the set's format and an example; the real set is built from real approved documents in a staging workspace.

## Building the set

1. Load the design partner's approved documents into a staging workspace.
2. Write 50 questions the way employees actually ask them — short, vague, misspelled, using their own words rather than the document's. For each, record which document answers it (`expect`, by title or id) and, where it is one step or section, which (`section`, e.g. `step:3` or `section:purpose`).
3. Write 15 questions that sound plausible but that **no** document answers (the assistant must refuse), including a few that name something in a space the evaluating user cannot see.
4. Save as JSON in the shape of `example-set.json`. Keep the set fixed; add to it deliberately, never edit it to make a run look better.

## Demo corpus (run the whole thing before the real set exists)

`demo/corpus.json` is a small fictional company (8 documents in 3 spaces) and `demo/set.json` has 20 answerable and 15 unanswerable questions over it, including near-misses such as a refund *amount* limit the policy never mentions. It exercises the refusal path with real models (H3-T3) and proves the setup end to end:

```bash
cd api
php artisan retrieval:seed-corpus ../spike/retrieval-eval/demo/corpus.json --workspace=<staging slug> --author=<editor email> --approver=<approver email>
php artisan retrieval:check-index --dry-run          # wait until it reports 0 unindexed
php artisan retrieval:eval ../spike/retrieval-eval/demo/set.json --workspace=<staging slug> --as=<email> --answer
```

`retrieval:seed-corpus` refuses to run in production and skips documents that already exist. A design partner's content can be loaded the same way once exported into the same JSON shape.

## Running

```bash
cd api
php artisan retrieval:eval ../spike/retrieval-eval/set.json --workspace=<slug> --as=<member email>
php artisan retrieval:eval ../spike/retrieval-eval/set.json --workspace=<slug> --as=<email> --answer   # also runs the assistant (LLM cost)
php artisan retrieval:eval ... --weights=1:0.6,1:0.3,1:1,1:0,0:1                                        # fusion settings to compare
```

The command searches **as that member**, so their permissions apply. Output goes to `api/storage/app/retrieval-eval/`: `summary.md` (one column per fusion setting) and `queries.csv` (every query, the rank the expected document came back at, and the top five titles).

| Metric (doc 08) | Target |
|---|---|
| Correct document in top 3 | > 90% |
| Answer factually consistent with the cited source | 100% — checked by reading `--answer` runs |
| Citations present on every non-refused answer | 100% |
| Correct refusal on unanswerable questions | > 95% |
| False refusal on answerable questions | < 5% |

Tuning: the live fusion weights are `RETRIEVAL_VECTOR_WEIGHT` / `RETRIEVAL_KEYWORD_WEIGHT` and the refusal threshold is `RETRIEVAL_MIN_SCORE`. Change them only with a before/after `summary.md` in the PR.
