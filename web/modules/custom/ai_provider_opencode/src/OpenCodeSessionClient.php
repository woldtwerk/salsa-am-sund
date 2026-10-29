<?php

declare(strict_types=1);

namespace Drupal\ai_provider_opencode;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Adds the session header that OpenCode Go requires to every request.
 *
 * Console uses the header to route a conversation consistently and to reuse
 * its prompt cache. Without it, OpenCode Go rejects chat requests.
 */
final class OpenCodeSessionClient implements ClientInterface {

  /**
   * Constructs an OpenCodeSessionClient.
   *
   * @param \Psr\Http\Client\ClientInterface $inner
   *   The HTTP client that sends the request.
   * @param string $sessionId
   *   The stable session ID.
   */
  public function __construct(
    private readonly ClientInterface $inner,
    private readonly string $sessionId,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function sendRequest(RequestInterface $request): ResponseInterface {
    return $this->inner->sendRequest($request->withHeader('x-opencode-session', $this->sessionId));
  }

}
