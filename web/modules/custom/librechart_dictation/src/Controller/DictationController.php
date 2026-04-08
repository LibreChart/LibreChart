<?php

declare(strict_types=1);

namespace Drupal\librechart_dictation\Controller;

use Drupal\Core\Controller\ControllerBase;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Handles dictation transcription requests, proxying to whisper.cpp.
 *
 * Accepts multipart audio uploads, validates them, and forwards to the
 * configured whisper.cpp HTTP server. Returns transcript JSON on success
 * or a structured error response per the dictation API contract.
 */
class DictationController extends ControllerBase {

  /**
   * Maximum allowed upload size in bytes (10 MB).
   */
  protected const MAX_UPLOAD_BYTES = 10 * 1024 * 1024;

  /**
   * Accepted audio MIME types.
   *
   * @var string[]
   */
  protected const ACCEPTED_TYPES = [
    'audio/webm',
    'audio/wav',
    'audio/mpeg',
    'audio/ogg',
    'audio/mp3',
  ];

  /**
   * Constructs a DictationController.
   *
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   The HTTP client for forwarding requests to whisper.cpp.
   */
  public function __construct(
    protected readonly ClientInterface $httpClient,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('http_client'),
    );
  }

  /**
   * Transcribes an audio file via whisper.cpp.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming HTTP request with multipart audio upload.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with {status: ok, transcript} on success, or error JSON.
   */
  public function transcribe(Request $request): JsonResponse {
    $file = $request->files->get('file');

    if (!$file) {
      return new JsonResponse([
        'status' => 'error',
        'message' => $this->t('No audio file provided.'),
      ], 422);
    }

    // Validate file size.
    if ($file->getSize() > static::MAX_UPLOAD_BYTES) {
      return new JsonResponse([
        'status' => 'error',
        'message' => $this->t('Audio file too large. Maximum size is 10 MB.'),
      ], 413);
    }

    // Validate MIME type.
    $mime = $file->getMimeType();
    if (!in_array($mime, static::ACCEPTED_TYPES, TRUE)) {
      return new JsonResponse([
        'status' => 'error',
        'message' => $this->t('Unsupported audio format. Accepted: WebM, WAV, MP3, OGG.'),
      ], 422);
    }

    $config = $this->config('librechart_dictation.settings');
    $whisper_url = rtrim((string) ($config->get('whisper_server_url') ?? 'http://127.0.0.1:8080'), '/');
    $language = (string) ($config->get('language') ?? 'es');

    try {
      $response = $this->httpClient->request('POST', $whisper_url . '/inference', [
        'multipart' => [
          [
            'name' => 'file',
            'contents' => fopen($file->getPathname(), 'r'),
            'filename' => $file->getClientOriginalName(),
          ],
          ['name' => 'response_format', 'contents' => 'json'],
          ['name' => 'language', 'contents' => $language],
        ],
        'timeout' => 30,
      ]);

      $body = json_decode((string) $response->getBody(), TRUE);
      $transcript = $body['text'] ?? '';

      return new JsonResponse([
        'status' => 'ok',
        'transcript' => trim($transcript),
      ]);
    }
    catch (ConnectException $e) {
      return new JsonResponse([
        'status' => 'error',
        'message' => $this->t('Dictation service unavailable. Please try again or type your notes.'),
      ], 503);
    }
    catch (RequestException $e) {
      return new JsonResponse([
        'status' => 'error',
        'message' => $this->t('Transcription failed. Please try again.'),
      ], 422);
    }
  }

}
