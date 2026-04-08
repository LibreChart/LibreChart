# Speech-to-Text: Self-Hosted Options for Doctor Dictation

**Date:** 2026-03-15
**Context:** Librechart EMR rebuild — LAN-hosted Linux server, no internet dependency, all assets must be self-hosted.

---

## Requirements

- Fully self-hosted — no cloud API calls
- Runs on-premises Linux server (modest hardware assumed)
- Used for doctor dictation into clinical note fields
- Must integrate with Drupal frontend (likely via JavaScript in-browser or server-side PHP)

---

## Options Evaluated

### 1. Browser Web Speech API

**Verdict: Rejected**

The Web Speech API is built into Chromium/Chrome and provides zero-setup dictation, but it depends on Google's cloud services for transcription. Even in "offline" mode, it requires an internet-connected backend.

- No self-hosted path
- Data leaves the premises — unacceptable for clinical use
- Cannot be used in an air-gapped or LAN-only environment

---

### 2. Vosk

**Verdict: Viable — recommended for low-resource servers**

Vosk is a lightweight, offline speech recognition toolkit with a small footprint. It can run entirely in-browser via WebAssembly or as a server-side process.

- **Model sizes:** 40 MB (small, en-US) up to ~1.8 GB (large)
- **RAM usage:** ~200–400 MB for the small model
- **Accuracy:** Good for dictation with clear speech; lower than Whisper on noisy audio
- **Integration options:**
  - WebAssembly (runs in-browser, no server load)
  - Python or Node.js server daemon with REST API
- **Drupal path:** WASM in-browser → JavaScript sends transcript to Drupal field, or server daemon called via custom Drupal module

**Use when:** Server RAM is constrained (< 2 GB available), or a fully client-side (browser WASM) solution is preferred.

**Reference:** https://alphacephei.com/vosk/

---

### 3. Whisper.cpp

**Verdict: Primary recommendation — best accuracy/resource tradeoff**

Whisper.cpp is a C++ port of OpenAI's Whisper model. It runs entirely offline, supports a range of model sizes, and produces state-of-the-art transcription accuracy — including for accented speech and medical terminology with fine-tuning.

- **Model sizes:** `tiny` (~75 MB) through `large-v3` (~2.9 GB)
- **RAM usage:** ~500 MB (`small` model) to ~3 GB (`large`)
- **Recommended model:** `small.en` or `medium.en` for a balance of speed and accuracy on clinical dictation
- **Accuracy:** Significantly better than Vosk, especially on varied speakers
- **Integration options:**
  - Run as a persistent server via `whisper-server` (built-in HTTP server in whisper.cpp)
  - Or invoke as a CLI process from PHP via `exec()` / `proc_open()`
- **Drupal path:**
  - Browser records audio (MediaRecorder API → WAV/WebM)
  - JavaScript POSTs audio blob to a custom Drupal endpoint
  - Drupal module passes audio to whisper.cpp HTTP server or CLI
  - Transcription returned and populated into the clinical note field

**Hardware note:** `small.en` transcribes ~10–15× real-time on a modern CPU. A 1-minute dictation takes ~4–6 seconds. GPU acceleration available but not required.

**Use when:** Accuracy matters and the server has at least 1–2 GB RAM available (which is typical for any modern server).

**Reference:** https://github.com/ggerganov/whisper.cpp

---

### 4. Drupal AI Module + Ollama

**Verdict: Future path — currently too heavy for this use case**

The Drupal AI module provides a unified API abstraction layer for AI providers, and Ollama can serve local LLMs/speech models. This path is architecturally appealing for a future where Librechart has broader AI features (summarisation, coding assistance, etc.).

- **Current limitation:** Ollama does not natively serve Whisper-style audio transcription as a first-class feature; the integration is experimental
- **Resource cost:** Ollama's overhead makes it disproportionate for dictation alone
- **When to revisit:** If Librechart adopts the Drupal AI module for other features (e.g., clinical note summarisation), STT could be routed through the same abstraction layer

---

## Recommendation Summary

| Option | Accuracy | RAM | Integration Complexity | Verdict |
|---|---|---|---|---|
| Web Speech API | High | None | Low | Rejected (cloud) |
| Vosk (WASM) | Moderate | Low | Low | Use on low-RAM servers |
| Whisper.cpp | High | Medium | Medium | **Primary recommendation** |
| Drupal AI + Ollama | High | High | High | Future / deferred |

**Immediate path:** Deploy `whisper.cpp` as a local HTTP server on the LAN host. Build a small custom Drupal module that:
1. Exposes a `/api/dictation/transcribe` endpoint accepting audio blobs
2. Forwards to whisper.cpp and returns the transcript
3. JavaScript in the clinical note form handles recording and field population

---

## Open Questions

- What server hardware specs does the LAN host have? (Determines model size choice)
- Is GPU acceleration available? (Would enable `medium` or `large` models at acceptable speed)
- Should transcription be real-time streaming or submit-on-complete? (Whisper.cpp supports both)
