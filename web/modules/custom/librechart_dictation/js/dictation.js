/**
 * @file
 * Librechart dictation — attaches microphone button to text fields.
 *
 * Implements a 5-state machine: idle → recording → processing → success → error.
 * Uses the MediaRecorder API to capture audio and POST to /api/dictation/transcribe.
 * Transcript is inserted at cursor position in the target text field.
 */

(function (Drupal, drupalSettings) {
  'use strict';

  /**
   * Dictation behavior — attaches to all [data-dictation-enabled] elements.
   *
   * @type {Drupal~behavior}
   */
  Drupal.behaviors.librechartDictation = {
    attach: function (context) {
      const fields = once(
        'librechart-dictation',
        '[data-dictation-enabled]',
        context
      );

      fields.forEach(function (field) {
        const textarea = field.tagName === 'TEXTAREA' ? field : field.querySelector('textarea');
        if (!textarea) {
          return;
        }

        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'dictation-button dictation-button--idle';
        button.setAttribute('aria-label', Drupal.t('Start dictation'));
        button.textContent = Drupal.t('🎤 Dictate');

        field.parentNode.insertBefore(button, field.nextSibling);

        let mediaRecorder = null;
        let audioChunks = [];
        let state = 'idle';
        const maxDuration = (drupalSettings.librechartDictation || {}).maxAudioDuration || 300;

        function setState(newState, message) {
          state = newState;
          button.className = 'dictation-button dictation-button--' + newState;
          switch (newState) {
            case 'idle':
              button.textContent = Drupal.t('🎤 Dictate');
              button.disabled = false;
              button.setAttribute('aria-label', Drupal.t('Start dictation'));
              break;

            case 'recording':
              button.textContent = Drupal.t('⏹ Stop');
              button.disabled = false;
              button.setAttribute('aria-label', Drupal.t('Stop recording'));
              break;

            case 'processing':
              button.textContent = Drupal.t('⏳ Processing…');
              button.disabled = true;
              button.setAttribute('aria-label', Drupal.t('Processing audio'));
              break;

            case 'success':
              button.textContent = Drupal.t('✓ Done');
              button.disabled = false;
              button.setAttribute('aria-label', Drupal.t('Dictation complete'));
              setTimeout(function () {
                setState('idle');
              }, 2000);
              break;

            case 'error':
              button.textContent = message || Drupal.t('Error — try again');
              button.disabled = false;
              button.setAttribute('aria-label', Drupal.t('Dictation error'));
              setTimeout(function () {
                setState('idle');
              }, 4000);
              break;
          }
        }

        function insertAtCursor(text) {
          const start = textarea.selectionStart;
          const end = textarea.selectionEnd;
          const before = textarea.value.substring(0, start);
          const after = textarea.value.substring(end);
          textarea.value = before + text + after;
          const newPos = start + text.length;
          textarea.selectionStart = textarea.selectionEnd = newPos;
          textarea.dispatchEvent(new Event('input', { bubbles: true }));
        }

        async function startRecording() {
          try {
            const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            audioChunks = [];
            mediaRecorder = new MediaRecorder(stream);

            mediaRecorder.addEventListener('dataavailable', function (event) {
              if (event.data.size > 0) {
                audioChunks.push(event.data);
              }
            });

            mediaRecorder.addEventListener('stop', async function () {
              setState('processing');
              stream.getTracks().forEach(function (track) {
 track.stop(); });

              const blob = new Blob(audioChunks, { type: 'audio/webm' });
              const formData = new FormData();
              formData.append('file', blob, 'recording.webm');

              try {
                const transcribeUrl = (drupalSettings.librechartDictation || {}).transcribeUrl || '/api/dictation/transcribe';
                const response = await fetch(transcribeUrl, {
                  method: 'POST',
                  body: formData,
                  headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                  },
                });

                const data = await response.json();

                if (response.ok && data.status === 'ok') {
                  if (data.transcript) {
                    insertAtCursor(data.transcript + ' ');
                  }
                  setState('success');
                }
                else {
                  setState('error', data.message || Drupal.t('Transcription failed.'));
                }
              }
              catch (err) {
                setState('error', Drupal.t('Dictation service unavailable. Please type your notes.'));
              }
            });

            mediaRecorder.start();
            setState('recording');

            // Auto-stop after max duration.
            setTimeout(function () {
              if (state === 'recording' && mediaRecorder) {
                mediaRecorder.stop();
              }
            }, maxDuration * 1000);
          }
          catch (err) {
            setState('error', Drupal.t('Microphone access denied.'));
          }
        }

        button.addEventListener('click', function () {
          if (state === 'idle') {
            startRecording();
          }
          else if (state === 'recording' && mediaRecorder) {
            mediaRecorder.stop();
          }
        });
      });
    }
  };

})(Drupal, drupalSettings);
