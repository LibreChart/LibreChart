# Whisper.cpp Setup Guide

This document describes how to compile whisper.cpp, download the multilingual model,
and run the whisper HTTP server as a systemd service on the production Linux server.

## Prerequisites

- Linux server with at least 1 GB RAM (model: ~465 MB RAM at runtime)
- GCC/Clang build tools
- CMake 3.14+
- curl or wget

```bash
sudo apt-get install -y build-essential cmake curl
```

## Compilation

```bash
# Clone the repository
git clone https://github.com/ggerganov/whisper.cpp
cd whisper.cpp

# Build with CMake
cmake -B build -DWHISPER_BUILD_SERVER=ON
cmake --build build --config Release

# Verify the server binary was built
ls build/bin/whisper-server
```

## Download the Multilingual Small Model

The `small` model supports both Spanish and English with ~465 MB RAM usage.

```bash
# From the whisper.cpp directory
bash ./models/download-ggml-model.sh small

# Verify the model file is present
ls models/ggml-small.bin
```

## Test the Server Manually

```bash
./build/bin/whisper-server \
  --model models/ggml-small.bin \
  --host 127.0.0.1 \
  --port 8080 \
  --language es
```

Test with a sample audio file:

```bash
curl -X POST http://127.0.0.1:8080/inference \
  -F "file=@/path/to/sample.wav" \
  -F "response_format=json" \
  -F "language=es"
```

## systemd Service Unit

Create `/etc/systemd/system/whisper-server.service`:

```ini
[Unit]
Description=Whisper.cpp HTTP transcription server
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/opt/whisper.cpp
ExecStart=/opt/whisper.cpp/build/bin/whisper-server \
    --model /opt/whisper.cpp/models/ggml-small.bin \
    --host 127.0.0.1 \
    --port 8080 \
    --language es
Restart=always
RestartSec=5
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
```

Enable and start the service:

```bash
sudo systemctl daemon-reload
sudo systemctl enable whisper-server
sudo systemctl start whisper-server
sudo systemctl status whisper-server
```

## Librechart Configuration

Once the server is running, configure the URL in Drupal at:
**Admin > Configuration > Librechart > Dictation Settings** (`/admin/config/librechart/dictation`)

Default settings:
- Server URL: `http://127.0.0.1:8080`
- Language: Spanish (es)
- Max duration: 300 seconds

## Memory and Performance Notes

- `ggml-small.bin`: ~465 MB RAM, supports Spanish and English, good accuracy
- `ggml-base.bin`: ~148 MB RAM, lower accuracy (use if server has <512 MB free RAM)
- Transcription time for a 1-minute recording: ~3–8 seconds on modest hardware

## Security Notes

The whisper server is bound to `127.0.0.1` only (localhost). It is not accessible
from outside the server. All requests are proxied through Drupal's
`/api/dictation/transcribe` endpoint, which enforces the `use dictation` permission.
