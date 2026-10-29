<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_events\Unit;

use Drupal\ai\Base\AiProviderClientBase;
use Drupal\ai\Base\OpenAiBasedProviderClientBase;
use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\ai\Plugin\ProviderProxy;
use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Tests\UnitTestCase;
use Drupal\workspace_events\Attendance\AiProviderVisionBackend;
use Drupal\workspace_events\Attendance\MistralOcrBackend;
use Drupal\workspace_events\Attendance\MistralOcrClientFactoryInterface;
use Drupal\workspace_events\Attendance\OcrBackendResolver;
use Drupal\workspace_events\AttendanceRecognition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the AI provider backend and the resolver.
 */
#[CoversClass(AiProviderVisionBackend::class)]
#[CoversClass(OcrBackendResolver::class)]
#[Group('workspace_events')]
final class AiProviderVisionBackendTest extends UnitTestCase {

  /**
   * Tests that the prompt and the image are sent to the provider.
   */
  public function testRecognizeText(): void {
    $output = new ChatOutput(new ChatMessage('assistant', "Max Mustermann\nErika Musterfrau"), [], []);
    $captured = new \stdClass();
    $plugin = $this->getMockBuilder(OpenAiBasedProviderClientBase::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['chat', 'getModelSettings', 'getSupportedOperationTypes'])
      ->getMock();
    $plugin->method('getModelSettings')->willReturnArgument(1);
    $plugin->method('getSupportedOperationTypes')->willReturn(['chat']);
    $plugin->method('chat')->willReturnCallback(function (array|string|ChatInput $input, string $model_id) use ($output, $captured): ChatOutput {
      $captured->input = is_object($input) ? $input : NULL;
      $captured->modelId = $model_id;
      return $output;
    });

    $proxy = new class($plugin) extends ProviderProxy {

      /**
       * Constructs the test proxy.
       *
       * @param \Drupal\ai\Base\AiProviderClientBase $plugin
       *   The provider plugin.
       */
      public function __construct(AiProviderClientBase $plugin) {
        $this->plugin = $plugin;
      }

    };

    $manager = $this->createMock(PluginManagerInterface::class);
    $manager->expects($this->once())
      ->method('createInstance')
      ->with('opencode_go')
      ->willReturn($proxy);

    $backend = new AiProviderVisionBackend($manager, 'opencode_go', 'kimi-k3');
    $text = $backend->recognizeText('image-bytes', 'image/png');

    self::assertSame("Max Mustermann\nErika Musterfrau", $text);
    self::assertSame('kimi-k3', $captured->modelId);
    self::assertInstanceOf(ChatInput::class, $captured->input);

    $messages = $captured->input->getMessages();
    self::assertCount(1, $messages);
    self::assertSame(AiProviderVisionBackend::PROMPT, $messages[0]->getText());

    $images = $messages[0]->getImages();
    self::assertCount(1, $images);
    self::assertSame('image/png', $images[0]->getMimeType());
    self::assertSame('image-bytes', $images[0]->getBinary());
  }

  /**
   * Tests that the resolver builds the configured AI provider backend.
   */
  public function testResolverReturnsAiProviderBackend(): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnMap([
      ['backend', AttendanceRecognition::BACKEND_AI_PROVIDER],
      ['provider_id', 'opencode_go'],
      ['model_id', 'kimi-k3'],
    ]);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with(AttendanceRecognition::SETTINGS)
      ->willReturn($config);

    $resolver = new OcrBackendResolver(
      $configFactory,
      new MistralOcrBackend($this->createMock(MistralOcrClientFactoryInterface::class)),
      (new \ReflectionClass(AiProviderPluginManager::class))->newInstanceWithoutConstructor(),
    );

    self::assertInstanceOf(AiProviderVisionBackend::class, $resolver->resolve());
  }

  /**
   * Tests the clean error when the AI module is not installed.
   */
  public function testResolverWithoutAiModuleThrows(): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnMap([
      ['backend', AttendanceRecognition::BACKEND_AI_PROVIDER],
      ['provider_id', 'opencode_go'],
      ['model_id', 'kimi-k3'],
    ]);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with(AttendanceRecognition::SETTINGS)
      ->willReturn($config);

    $resolver = new OcrBackendResolver(
      $configFactory,
      new MistralOcrBackend($this->createMock(MistralOcrClientFactoryInterface::class)),
      NULL,
    );

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('The AI module is not available.');
    $resolver->resolve();
  }

}
