# Contract: Dictation Transcription Endpoint

**Module**: `librechart_dictation`
**Date**: 2026-03-15

This is the only external-facing HTTP interface Librechart exposes beyond standard Drupal routes. It acts as a proxy between the browser's audio recording and the locally-hosted whisper.cpp transcription server.

---

## Endpoint

```
POST /api/dictation/transcribe
```

**Authentication**: Requires an active Drupal session cookie with a role that has the `use dictation` permission (granted to: Clinician, Triage Nurse, Physical Therapist by default).

---

## Request

**Content-Type**: `multipart/form-data`

| Field | Type | Required | Description |
|---|---|---|---|
| `audio` | binary file | Yes | Audio recording from MediaRecorder API. Accepted formats: WebM (Opus), WAV, MP3, OGG |
| `field_name` | string | No | The Drupal field machine name being dictated into. Used for logging only. |

**Max file size**: 10 MB (approximately 5 minutes of compressed audio at typical quality).

**Example (JavaScript)**:
```js
const formData = new FormData();
formData.append('audio', audioBlob, 'dictation.webm');
formData.append('field_name', 'clinical_notes');

const response = await fetch('/api/dictation/transcribe', {
  method: 'POST',
  body: formData,
});
```

---

## Response

**Content-Type**: `application/json`

### Success — 200 OK

```json
{
  "status": "ok",
  "transcript": "Patient presents with a three-day history of productive cough and low-grade fever. No significant past medical history."
}
```

| Field | Type | Description |
|---|---|---|
| `status` | string | Always `"ok"` on success |
| `transcript` | string | The transcribed text, trimmed of leading/trailing whitespace |

### Error — 422 Unprocessable Entity

Returned when audio is present but transcription fails (e.g., unintelligible audio, empty recording).

```json
{
  "status": "error",
  "code": "transcription_failed",
  "message": "Could not transcribe audio. Please try again or enter text manually."
}
```

### Error — 503 Service Unavailable

Returned when the local whisper.cpp server cannot be reached.

```json
{
  "status": "error",
  "code": "transcription_service_unavailable",
  "message": "Dictation service is temporarily unavailable. Please enter text manually."
}
```

### Error — 403 Forbidden

Returned when the request lacks a valid session or the user lacks the `use dictation` permission.

```json
{
  "status": "error",
  "code": "access_denied",
  "message": "You do not have permission to use dictation."
}
```

### Error — 413 Payload Too Large

Returned when the audio file exceeds the 10 MB limit.

```json
{
  "status": "error",
  "code": "audio_too_large",
  "message": "Recording is too long. Please record shorter segments."
}
```

---

## Upstream: whisper.cpp Server

The Drupal module forwards audio to the whisper.cpp HTTP server running locally on the same machine.

**Internal URL**: `http://127.0.0.1:8080/inference` (configurable via Drupal admin config form)

**whisper.cpp request** (multipart/form-data):

| Field | Value |
|---|---|
| `file` | Audio blob forwarded from browser |
| `response_format` | `json` |
| `language` | `en` (configurable) |

**whisper.cpp response**:
```json
{
  "text": " Patient presents with a three-day history..."
}
```

The Drupal module trims the text and returns it to the browser as the `transcript` field.

---

## JavaScript Integration Contract

The `librechart_dictation` module attaches a microphone button to every `text_long` field with the `data-dictation-enabled` HTML attribute. The button's behavior contract:

| State | Visual indicator | Button state |
|---|---|---|
| Idle | No indicator | Microphone icon, enabled |
| Recording | Pulsing red dot | Stop icon, enabled |
| Processing | Spinner | Disabled |
| Success | Brief green flash | Returns to microphone icon |
| Error | Error message below field | Returns to microphone icon |

**Keyboard access**: The dictation button is focusable and activatable via Enter/Space. Screen reader label: "Start dictation for [field label]".

---

## Configuration

Exposed via Drupal admin at `/admin/config/librechart/dictation`:

| Setting | Default | Description |
|---|---|---|
| Whisper server URL | `http://127.0.0.1:8080` | Internal URL of whisper.cpp server |
| Language | `en` | Transcription language passed to whisper.cpp |
| Max audio duration (seconds) | `300` | Client-side recording limit before auto-stop |
| Enabled roles | Clinician, Triage Nurse, PT | Roles that see the dictation button |
