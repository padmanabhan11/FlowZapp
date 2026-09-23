# Cross-browser capture check (C1-T5)

FlowZapp records the screen with `getDisplayMedia` and the voice with `getUserMedia`, mixes them into one `MediaRecorder`, and uploads the result. Browsers differ in what the recorder can produce, so the container is chosen per browser (`src/app/features/recordings/capture-support.ts`) and the upload is labelled with what was actually recorded.

| Browser | Screen share | Recorder output | How it is verified |
|---|---|---|---|
| Chrome | yes | WebM VP9 + Opus | `run.mjs` (automated, fake devices) + unit matrix |
| Edge | yes (Chromium) | WebM VP9 + Opus | unit matrix; manual check below |
| Firefox | yes | WebM VP8 + Opus | unit matrix; manual check below |
| Safari 14.1+ (macOS) | yes | **MP4** H.264 + AAC | unit matrix; manual check below |
| iOS / Android | no | — | the modal shows the upload path with the reason |

## Automated (Chromium)

```bash
npm i -g playwright            # once; uses its bundled Chromium
node web/e2e/capture-probe/run.mjs
```

Exit code 0 and `"verdict": "PASS"` mean: screen and microphone were captured together, the recorder produced data, pause/resume worked, and the recording plays back.

## Manual (Firefox, Safari, Edge) — do this once per release

Serve the folder over `localhost` (screen sharing needs a secure context) and open it in each browser:

```bash
cd web/e2e/capture-probe && python3 -m http.server 8765
# open http://localhost:8765/probe.html
```

Press **Run**, share a window, allow the microphone, speak for three seconds. Record the result here:

| Browser + version | Date | `recorderMime` | `playable` | Verdict | By |
|---|---|---|---|---|---|
| Chromium 141 (headless, fake devices) | 2026-09-23 | video/webm;codecs=vp9,opus | true | PASS | Claude |
| Edge | | | | | |
| Firefox | | | | | |
| Safari | | | | | |

Then, in the app itself, record a 30-second process in each browser and confirm the recording reaches **Draft ready**. For Safari, also confirm the recording's type shows as MP4.
