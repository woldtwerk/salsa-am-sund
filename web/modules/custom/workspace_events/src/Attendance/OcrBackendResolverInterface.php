<?php

declare(strict_types=1);

namespace Drupal\workspace_events\Attendance;

/**
 * Picks the OCR backend that the site configuration selected.
 */
interface OcrBackendResolverInterface {

  /**
   * Returns the configured OCR backend.
   *
   * @return \Drupal\workspace_events\Attendance\OcrBackendInterface
   *   The backend for the currently configured provider.
   *
   * @throws \RuntimeException
   *   When the configured backend is unknown or incomplete.
   */
  public function resolve(): OcrBackendInterface;

}
