<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_events\Unit;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\file\FileInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\workspace_events\Attendance\AttendancePhotoTextParser;
use Drupal\workspace_events\Attendance\AttendanceRecognitionException;
use Drupal\workspace_events\Attendance\AttendanceRecognizer;
use Drupal\workspace_events\Attendance\MistralOcrClientFactoryInterface;
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
#[Group('workspace_events')]
final class AttendanceRecognizerTest extends UnitTestCase {

  /**
   * The path of the fake attendance photo.
   */
  private string $photoPath;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->photoPath = tempnam(sys_get_temp_dir(), 'attendance-photo-') ?: '';
    file_put_contents($this->photoPath, 'fake-image-bytes');
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if ($this->photoPath !== '' && file_exists($this->photoPath)) {
      unlink($this->photoPath);
    }
    parent::tearDown();
  }

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
    $names = $recognizer->recognize($this->createPhoto());

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
    $this->createRecognizer($mock)->recognize($this->createPhoto());
  }

  /**
   * Tests that a timeout becomes a clean recognition error.
   */
  public function testRecognizeTimeout(): void {
    $mock = new MockHandler([
      new ConnectException('Timeout', new Request('POST', 'https://api.mistral.ai/v1/ocr')),
    ]);

    $this->expectException(AttendanceRecognitionException::class);
    $this->createRecognizer($mock)->recognize($this->createPhoto());
  }

  /**
   * Tests an empty OCR response.
   */
  public function testRecognizeEmptyResponse(): void {
    $mock = new MockHandler([
      new Response(200, [], json_encode(['pages' => []], JSON_THROW_ON_ERROR)),
    ]);

    self::assertSame([], $this->createRecognizer($mock)->recognize($this->createPhoto()));
  }

  /**
   * Tests that a missing photo becomes a clean recognition error.
   */
  public function testRecognizeMissingPhoto(): void {
    $mock = new MockHandler([]);

    $photo = $this->createMock(FileInterface::class);
    $photo->method('getFileUri')->willReturn('private://missing.jpg');
    $photo->method('getMimeType')->willReturn('image/jpeg');

    $fileSystem = $this->createMock(FileSystemInterface::class);
    $fileSystem->method('realpath')->willReturn(FALSE);

    $recognizer = new AttendanceRecognizer(
      $this->createClientFactory($mock),
      new AttendancePhotoTextParser(),
      $fileSystem,
      $this->createLoggerFactory(),
    );

    $this->expectException(AttendanceRecognitionException::class);
    $recognizer->recognize($photo);
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
    $fileSystem = $this->createMock(FileSystemInterface::class);
    $fileSystem->method('realpath')->willReturn($this->photoPath);

    return new AttendanceRecognizer(
      $this->createClientFactory($mock),
      new AttendancePhotoTextParser(),
      $fileSystem,
      $this->createLoggerFactory(),
    );
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

  /**
   * Creates the fake photo entity.
   *
   * @return \Drupal\file\FileInterface
   *   The photo.
   */
  private function createPhoto(): FileInterface {
    $photo = $this->createMock(FileInterface::class);
    $photo->method('getFileUri')->willReturn('private://photo.jpg');
    $photo->method('getMimeType')->willReturn('image/jpeg');
    return $photo;
  }

}
