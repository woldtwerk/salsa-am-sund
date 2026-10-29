<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_provider_opencode\Unit;

use Drupal\ai_provider_opencode\Plugin\AiProvider\OpenCodeZenProvider;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Http\ClientFactory;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\key\KeyRepositoryInterface;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Tests the OpenCode provider model discovery.
 */
#[CoversClass(OpenCodeZenProvider::class)]
#[Group('ai_provider_opencode')]
final class OpenCodeProviderTest extends UnitTestCase {

  /**
   * Tests that free and training models are left out of the model list.
   */
  public function testModelListExcludesTrainingModels(): void {
    $provider = $this->provider($this->config(''), new MockHandler([
      new Response(200, [], json_encode([
        'object' => 'list',
        'data' => [
          ['id' => 'glm-5.3', 'object' => 'model', 'created' => 1, 'owned_by' => 'opencode'],
          ['id' => 'glm-5.3-flash-free', 'object' => 'model', 'created' => 1, 'owned_by' => 'opencode'],
          ['id' => 'big-pickle', 'object' => 'model', 'created' => 1, 'owned_by' => 'opencode'],
          ['id' => 'nemotron-3.5-super-vl-free', 'object' => 'model', 'created' => 1, 'owned_by' => 'opencode'],
          ['id' => 'kimi-k3', 'object' => 'model', 'created' => 1, 'owned_by' => 'opencode'],
        ],
      ], JSON_THROW_ON_ERROR)),
    ]));

    self::assertSame(['glm-5.3', 'kimi-k3'], array_keys($provider->getModels('chat')));
  }

  /**
   * Tests that the client uses the base URL from the provider settings.
   */
  public function testClientUsesConfiguredBaseUrl(): void {
    $provider = $this->provider($this->config('https://example.test/openai/v1'), new MockHandler());

    $provider->getClient('test-key');

    self::assertSame('https://example.test/openai/v1', $provider->getEndpoint());
  }

  /**
   * Tests that a streamed chat request carries the session header.
   */
  public function testStreamedChatSendsSessionHeader(): void {
    $stream = 'data: ' . json_encode([
      'id' => 'chatcmpl-test',
      'object' => 'chat.completion.chunk',
      'created' => 1,
      'model' => 'kimi-k3',
      'choices' => [
        [
          'index' => 0,
          'delta' => ['role' => 'assistant', 'content' => 'OK'],
          'finish_reason' => 'stop',
        ],
      ],
    ], JSON_THROW_ON_ERROR) . "\n\ndata: [DONE]\n\n";

    $mock = new MockHandler([
      new Response(200, ['Content-Type' => 'text/event-stream'], $stream),
    ]);

    $provider = $this->provider($this->config(''), $mock);
    $provider->setAuthentication('test-key');
    $provider->streamedOutput(TRUE);
    $input = new ChatInput([new ChatMessage('user', 'Hello')]);
    $provider->chat($input, 'kimi-k3');

    $request = $mock->getLastRequest();
    self::assertNotNull($request);
    self::assertStringStartsWith('ses_', $request->getHeaderLine('x-opencode-session'));

    $body = json_decode((string) $request->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
    self::assertTrue($body['stream']);
  }

  /**
   * Creates a provider with a mocked HTTP client and cache.
   *
   * @param \Drupal\Core\Config\ImmutableConfig $config
   *   The provider settings.
   * @param \GuzzleHttp\Handler\MockHandler $mock
   *   The mock handler of the HTTP client.
   *
   * @return \Drupal\ai_provider_opencode\Plugin\AiProvider\OpenCodeZenProvider
   *   The provider under test.
   */
  private function provider(ImmutableConfig $config, MockHandler $mock): OpenCodeZenProvider {
    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->method('get')->willReturn(FALSE);

    $aiSettings = $this->createMock(ImmutableConfig::class);
    $aiSettings->method('get')->willReturnMap([
      ['request_timeout', 60],
    ]);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturnMap([
      ['ai_provider_opencode.zen.settings', $config],
      ['ai.settings', $aiSettings],
    ]);

    $handler = HandlerStack::create($mock);

    $provider = new class(
      OpenCodeZenProvider::ID,
      ['provider' => 'ai_provider_opencode'],
      new GuzzleClient(['handler' => $handler]),
      $configFactory,
      $this->createMock(LoggerChannelFactoryInterface::class),
      $cache,
      $this->createMock(KeyRepositoryInterface::class),
      $this->createMock(ModuleHandlerInterface::class),
      $this->createMock(EventDispatcherInterface::class),
      $this->createMock(FileSystemInterface::class),
    ) extends OpenCodeZenProvider {

      /**
       * {@inheritdoc}
       */
      public function getModelsConfig(): array {
        return [];
      }

      /**
       * Sets the HTTP client factory.
       *
       * @param \Drupal\Core\Http\ClientFactory $httpClientFactory
       *   The HTTP client factory.
       */
      public function setHttpClientFactory(ClientFactory $httpClientFactory): void {
        $this->httpClientFactory = $httpClientFactory;
      }

    };

    $provider->setHttpClientFactory(new ClientFactory($handler));

    return $provider;
  }

  /**
   * Creates the provider settings.
   *
   * @param string $baseUrl
   *   The configured base URL, empty for the default.
   *
   * @return \Drupal\Core\Config\ImmutableConfig
   *   The provider settings.
   */
  private function config(string $baseUrl): ImmutableConfig {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnMap([
      ['api_key', ''],
      ['base_url', $baseUrl],
    ]);
    return $config;
  }

}
