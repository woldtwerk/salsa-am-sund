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
   * Tests that chat requests carry the session header.
   */
  public function testChatSendsSessionHeader(): void {
    $mock = new MockHandler([
      new Response(200, [], json_encode([
        'id' => 'chatcmpl-test',
        'object' => 'chat.completion',
        'created' => 1,
        'model' => 'kimi-k3',
        'choices' => [
          [
            'index' => 0,
            'message' => ['role' => 'assistant', 'content' => 'OK'],
            'finish_reason' => 'stop',
          ],
        ],
        'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
      ], JSON_THROW_ON_ERROR)),
    ]);

    $provider = $this->provider($this->config(''), $mock);
    $provider->setAuthentication('test-key');
    $provider->chat(new ChatInput([new ChatMessage('user', 'Hello')]), 'kimi-k3');

    $request = $mock->getLastRequest();
    self::assertNotNull($request);
    self::assertStringStartsWith('ses_', $request->getHeaderLine('x-opencode-session'));
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

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('ai_provider_opencode.zen.settings')
      ->willReturn($config);

    return new class(
      OpenCodeZenProvider::ID,
      ['provider' => 'ai_provider_opencode'],
      new GuzzleClient(['handler' => HandlerStack::create($mock)]),
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

    };
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
