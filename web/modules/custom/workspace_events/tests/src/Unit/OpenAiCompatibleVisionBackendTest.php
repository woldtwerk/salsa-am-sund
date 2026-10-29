<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_events\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Http\ClientFactory;
use Drupal\key\KeyInterface;
use Drupal\key\KeyRepositoryInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\workspace_events\Attendance\MistralOcrBackend;
use Drupal\workspace_events\Attendance\MistralOcrClientFactoryInterface;
use Drupal\workspace_events\Attendance\OcrBackendResolver;
use Drupal\workspace_events\Attendance\OpenAiCompatibleVisionBackend;
use Drupal\workspace_events\AttendanceRecognition;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the OpenAI-compatible vision backend and the backend resolver.
 */
#[CoversClass(OpenAiCompatibleVisionBackend::class)]
#[CoversClass(OcrBackendResolver::class)]
#[Group('workspace_events')]
final class OpenAiCompatibleVisionBackendTest extends UnitTestCase {

  /**
   * Tests the request payload and a successful response.
   */
  public function testRecognizeText(): void {
    $mock = new MockHandler([
      new Response(200, [], json_encode([
        'choices' => [
          [
            'message' => [
              'role' => 'assistant',
              'content' => "Max Mustermann\nErika Musterfrau",
            ],
          ],
        ],
      ], JSON_THROW_ON_ERROR)),
    ]);

    $text = $this->createBackend($mock)->recognizeText('fake-image-bytes', 'image/png');

    self::assertSame("Max Mustermann\nErika Musterfrau", $text);

    $request = $mock->getLastRequest();
    self::assertNotNull($request);
    self::assertSame('https://opencode.ai/zen/go/v1/chat/completions', (string) $request->getUri());
    self::assertSame('Bearer test-key', $request->getHeaderLine('Authorization'));

    $body = json_decode((string) $request->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
    self::assertSame('kimi-k2.5', $body['model']);
    self::assertSame(0, $body['temperature']);
    self::assertSame(OpenAiCompatibleVisionBackend::PROMPT, $body['messages'][0]['content'][0]['text']);
    self::assertStringStartsWith('data:image/png;base64,', $body['messages'][0]['content'][1]['image_url']['url']);
  }

  /**
   * Tests that an error status is reported as an exception.
   */
  public function testRecognizeErrorStatus(): void {
    $mock = new MockHandler([
      new Response(400, [], 'Bad request'),
    ]);

    $this->expectException(\RuntimeException::class);
    $this->createBackend($mock)->recognizeText('fake-image-bytes', 'image/jpeg');
  }

  /**
   * Tests that a response without a choice is reported as an exception.
   */
  public function testRecognizeEmptyChoices(): void {
    $mock = new MockHandler([
      new Response(200, [], json_encode(['choices' => []], JSON_THROW_ON_ERROR)),
    ]);

    $this->expectException(\RuntimeException::class);
    $this->createBackend($mock)->recognizeText('fake-image-bytes', 'image/jpeg');
  }

  /**
   * Tests that the resolver builds the OpenCode backend from the config.
   */
  public function testResolverReturnsOpenCodeBackend(): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnMap([
      ['backend', AttendanceRecognition::BACKEND_OPENCODE_GO],
      [
        AttendanceRecognition::BACKEND_OPENCODE_GO,
        [
          'base_url' => 'https://opencode.ai/zen/go/v1',
          'model' => 'kimi-k2.5',
          'key' => AttendanceRecognition::OPENCODE_KEY_ID,
        ],
      ],
    ]);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with(AttendanceRecognition::SETTINGS)
      ->willReturn($config);

    $resolver = new OcrBackendResolver(
      $configFactory,
      new MistralOcrBackend($this->createMock(MistralOcrClientFactoryInterface::class)),
      $this->createMock(ClientFactory::class),
      $this->createMock(KeyRepositoryInterface::class),
    );

    self::assertInstanceOf(OpenAiCompatibleVisionBackend::class, $resolver->resolve());
  }

  /**
   * Creates the backend with a mocked HTTP client and Key entity.
   *
   * @param \GuzzleHttp\Handler\MockHandler $mock
   *   The mock handler.
   *
   * @return \Drupal\workspace_events\Attendance\OpenAiCompatibleVisionBackend
   *   The backend.
   */
  private function createBackend(MockHandler $mock): OpenAiCompatibleVisionBackend {
    $clientFactory = $this->createMock(ClientFactory::class);
    $clientFactory->method('fromOptions')
      ->willReturn(new GuzzleClient(['handler' => HandlerStack::create($mock)]));

    $key = $this->createMock(KeyInterface::class);
    $key->method('getKeyValue')->willReturn('test-key');

    $keyRepository = $this->createMock(KeyRepositoryInterface::class);
    $keyRepository->method('getKey')
      ->with(AttendanceRecognition::OPENCODE_KEY_ID)
      ->willReturn($key);

    return new OpenAiCompatibleVisionBackend(
      'https://opencode.ai/zen/go/v1',
      'kimi-k2.5',
      AttendanceRecognition::OPENCODE_KEY_ID,
      'OpenCode Go (Abo)',
      $clientFactory,
      $keyRepository,
    );
  }

}
