<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_events\Unit;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\workspace_events\Attendance\AttendancePhotoTextParser;
use Drupal\workspace_events\Attendance\AttendanceRecognitionException;
use Drupal\workspace_events\Attendance\AttendanceRecognizer;
use Drupal\workspace_events\Attendance\MistralOcrBackend;
use Drupal\workspace_events\Attendance\MistralOcrClientFactoryInterface;
use Drupal\workspace_events\Attendance\OcrBackendInterface;
use Drupal\workspace_events\Attendance\OcrBackendResolverInterface;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Partitech\PhpMistral\Clients\Mistral\MistralClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the Mistral OCR recognition with a mocked HTTP client.
 */
#[CoversClass(AttendanceRecognizer::class)]
#[CoversClass(AttendancePhotoTextParser::class)]
#[CoversClass(MistralOcrBackend::class)]
#[Group('workspace_events')]
final class AttendanceRecognizerTest extends UnitTestCase {

  /**
   * Tests a successful OCR response.
   */
  public function testRecognizeSuccess(): void {
    $mock = new MockHandler([
      new Response(200, [], json_encode([
        'pages' => [
          [
            'index' => 0,
            'markdown' => "- Max Mustermann\n- Erika Musterfrau\n26.10.2026",
          ],
        ],
      ], JSON_THROW_ON_ERROR)),
    ]);

    $recognizer = $this->createRecognizer($mock);
    $names = $recognizer->recognizeImage('fake-image-bytes', 'image/jpeg');

    self::assertSame(['Max Mustermann', 'Erika Musterfrau'], $names);

    $request = $mock->getLastRequest();
    self::assertNotNull($request);
    self::assertSame('https://api.mistral.ai/v1/ocr', (string) $request->getUri());
    self::assertStringStartsWith('Bearer ', $request->getHeaderLine('Authorization'));

    $body = json_decode((string) $request->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
    self::assertSame('mistral-ocr-latest', $body['model']);
    self::assertSame('image_url', $body['document']['type']);
    self::assertStringStartsWith('data:image/jpeg;base64,', $body['document']['image_url']);
  }

  /**
   * Tests that an error status becomes a clean recognition error.
   */
  public function testRecognizeErrorStatus(): void {
    $mock = new MockHandler([
      new Response(500, [], 'Internal error'),
    ]);

    $this->expectException(AttendanceRecognitionException::class);
    $this->createRecognizer($mock)->recognizeImage('fake-image-bytes', 'image/jpeg');
  }

  /**
   * Tests that a timeout becomes a clean recognition error.
   */
  public function testRecognizeTimeout(): void {
    $mock = new MockHandler([
      new ConnectException('Timeout', new Request('POST', 'https://api.mistral.ai/v1/ocr')),
    ]);

    $this->expectException(AttendanceRecognitionException::class);
    $this->createRecognizer($mock)->recognizeImage('fake-image-bytes', 'image/jpeg');
  }

  /**
   * Tests an empty OCR response.
   */
  public function testRecognizeEmptyResponse(): void {
    $mock = new MockHandler([
      new Response(200, [], json_encode(['pages' => []], JSON_THROW_ON_ERROR)),
    ]);

    self::assertSame([], $this->createRecognizer($mock)->recognizeImage('fake-image-bytes', 'image/jpeg'));
  }

  /**
   * Tests that empty image bytes become a clean recognition error.
   */
  public function testRecognizeEmptyImage(): void {
    $mock = new MockHandler([]);

    $this->expectException(AttendanceRecognitionException::class);
    $this->createRecognizer($mock)->recognizeImage('', 'image/jpeg');
  }

  /**
   * Creates the recognizer with a mocked HTTP client.
   *
   * @param \GuzzleHttp\Handler\MockHandler $mock
   *   The mock handler.
   *
   * @return \Drupal\workspace_events\Attendance\AttendanceRecognizer
   *   The recognizer.
   */
  private function createRecognizer(MockHandler $mock): AttendanceRecognizer {
    return new AttendanceRecognizer(
      $this->createBackendResolver($mock),
      new AttendancePhotoTextParser(),
      $this->createLoggerFactory(),
    );
  }

  /**
   * Creates a resolver that always returns the Mistral backend.
   *
   * @param \GuzzleHttp\Handler\MockHandler $mock
   *   The mock handler.
   *
   * @return \Drupal\workspace_events\Attendance\OcrBackendResolverInterface
   *   The backend resolver.
   */
  private function createBackendResolver(MockHandler $mock): OcrBackendResolverInterface {
    $backend = new MistralOcrBackend($this->createClientFactory($mock));

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
   * Creates a client factory that returns a client with the mock handler.
   *
   * @param \GuzzleHttp\Handler\MockHandler $mock
   *   The mock handler.
   *
   * @return \Drupal\workspace_events\Attendance\MistralOcrClientFactoryInterface
   *   The client factory.
   */
  private function createClientFactory(MockHandler $mock): MistralOcrClientFactoryInterface {
    $client = new MistralClient('test-key');
    $client->setClient(new GuzzleClient(['handler' => HandlerStack::create($mock)]));

    return new class($client) implements MistralOcrClientFactoryInterface {

      /**
       * Constructs the test client factory.
       *
       * @param \Partitech\PhpMistral\Clients\Mistral\MistralClient $client
       *   The Mistral client.
       */
      public function __construct(
        private readonly MistralClient $client,
      ) {}

      /**
       * {@inheritdoc}
       */
      public function create(): MistralClient {
        return $this->client;
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
