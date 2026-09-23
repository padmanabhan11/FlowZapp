# Runbook — recording pipeline failures

Each recording shows its own failure on the Recordings page (stage and reason, FR-314 halts included), so most failures are self-service: the contributor records again. Operators look here when failures cluster.

1. `select stage, error, count(*) from pipeline_jobs where status='failed' and created_at > now() - interval 1 day group by 1,2` (read replica / console) — which stage, which error.
2. By stage:
   - **transcribe:** provider (key, quota, outage) or FFmpeg cannot read the file (`The recording could not be read`). Check `worker-pipeline` logs; FFmpeg must be in the worker image (`api/Dockerfile.worker`).
   - **segment / generate:** Claude API errors or unusable output. Prompt changes are code changes — re-run `php artisan pipeline:eval` on the fixed set before shipping a fix.
   - **frames:** never fails a recording (03 §5); frames are just missing. Scene detection and dedup log warnings only.
3. Stuck in `transcribing` for over an hour with no failed job: the worker died mid-stage. `queue:retry` is safe — stages are idempotent by `job_key`.
4. Uploads that never completed are cleaned by `recordings:abandon-stale` (03:30 daily); an "Unfinished uploads" panel on the Recordings page lets the person resume.
