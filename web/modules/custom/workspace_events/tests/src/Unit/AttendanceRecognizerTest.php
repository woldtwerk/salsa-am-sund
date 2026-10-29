<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_events\Unit;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\workspace_events\Attendance\AttendancePhotoTextParser;
use Drupal\workspace_events\Attendance\AttendanceRecognitionException;
use Drupal\workspace_events\Attendance\AttendanceRecognizer;
use Drupal\workspace_events\Attendance\OcrBackendInterface;
use Drupal\workspace_events\Attendance\OcrBackendResolverInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the recognition of names from an OCR backend response.
 */
#[CoversClass(AttendanceRecognizer::class)]
#[CoversClass(AttendancePhotoTextParser::class)]
#[Group('workspace_events')]
final class AttendanceRecognizerTest extends UnitTestCase {

  /**
   * Tests that the names of a response are parsed.
   */
  public function testRecognizeSuccess(): void {
    $recognizer = $this->createRecognizer("- Max Mustermann\n- Erika Musterfrau\n26.10.2026");

    self::assertSame(['Max Mustermann', 'Erika Musterfrau'], $recognizer->recognizeImage('fake-image-bytes', 'image/jpeg'));
  }

  /**
   * Tests an empty backend response.
   */
  public function testRecognizeEmptyResponse(): void {
    self::assertSame([], $this->createRecognizer('')->recognizeImage('fake-image-bytes', 'image/jpeg'));
  }

  /**
   * Tests that a backend error becomes a clean recognition error.
   */
  public function testRecognizeBackendError(): void {
    $recognizer = $this->createRecognizer(NULL);

    $this->expectException(AttendanceRecognitionException::class);
    $recognizer->recognizeImage('fake-image-bytes', 'image/jpeg');
  }

  /**
   * Tests that empty image bytes become a clean recognition error.
   */
  public function testRecognizeEmptyImage(): void {
    $recognizer = $this->createRecognizer('Max Mustermann');

    $this->expectException(AttendanceRecognitionException::class);
    $recognizer->recognizeImage('', 'image/jpeg');
  }

  /**
   * Creates the recognizer with a stub backend.
   *
   * @param string|null $text
   *   The text the backend returns, or NULL to make it fail.
   *
   * @return \Drupal\workspace_events\Attendance\AttendanceRecognizer
   *   The recognizer.
   */
  private function createRecognizer(?string $text): AttendanceRecognizer {
    return new AttendanceRecognizer(
      $this->createBackendResolver($text),
      new AttendancePhotoTextParser(),
      $this->createLoggerFactory(),
    );
  }

  /**
   * Creates a resolver that always returns the stub backend.
   *
   * @param string|null $text
   *   The text the backend returns, or NULL to make it fail.
   *
   * @return \Drupal\workspace_events\Attendance\OcrBackendResolverInterface
   *   The backend resolver.
   */
  private function createBackendResolver(?string $text): OcrBackendResolverInterface {
    $backend = new class($text) implements OcrBackendInterface {

      /**
       * Constructs the stub backend.
       *
       * @param string|null $text
       *   The text to return, or NULL to fail.
       */
      public function __construct(
        private readonly ?string $text,
      ) {}

      /**
       * {@inheritdoc}
       */
      public function recognizeText(string $contents, string $mimeType): string {
        if ($this->text === NULL) {
          throw new \RuntimeException('The provider failed.');
        }
        return $this->text;
      }

      /**
       * {@inheritdoc}
       */
      public function label(): string {
        return 'Test backend';
      }

    };

    return new class($backend) implements OcrBackendResolverInterface {

      /**
       * Constructs the test resolver.
       *
       * @param \Drupal\workspace_events\Attendance\OcrBackendInterface $backend
       *   The backend.
       */
      public function __construct(
        private readonly OcrBackendInterface $backend,
      ) {}

      /**
       * {@inheritdoc}
       */
      public function resolve(): OcrBackendInterface {
        return $this->backend;
      }

    };
  }

  /**
   * Creates a logger factory for the recognizer.
   *
   * @return \Drupal\Core\Logger\LoggerChannelFactoryInterface
   *   The logger factory.
   */
  private function createLoggerFactory(): LoggerChannelFactoryInterface {
    $logger = $this->createMock(LoggerChannelInterface::class);
    $factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $factory->method('get')->willReturn($logger);
    return $factory;
  }

}
