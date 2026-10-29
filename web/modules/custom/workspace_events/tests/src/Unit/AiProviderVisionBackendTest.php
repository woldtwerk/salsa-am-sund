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
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\workspace_events\Attendance\AiProviderVisionBackend;
use Drupal\workspace_events\Attendance\OcrBackendResolver;
use Drupal\workspace_events\AttendanceRecognition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Tests the AI provider backend and the resolver.
 */
#[CoversClass(AiProviderVisionBackend::class)]
#[CoversClass(OcrBackendResolver::class)]
#[CoversClass(AttendanceRecognition::class)]
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

    $backend = new AiProviderVisionBackend($manager, 'opencode_go', 'kimi-k3', 'Test-Prompt');
    $text = $backend->recognizeText('image-bytes', 'image/png');

    self::assertSame("Max Mustermann\nErika Musterfrau", $text);
    self::assertSame('kimi-k3', $captured->modelId);
    self::assertInstanceOf(ChatInput::class, $captured->input);

    $messages = $captured->input->getMessages();
    self::assertCount(1, $messages);
    self::assertSame('Test-Prompt', $messages[0]->getText());

    $images = $messages[0]->getImages();
    self::assertCount(1, $images);
    self::assertSame('image/png', $images[0]->getMimeType());
    self::assertSame('image-bytes', $images[0]->getBinary());
  }

  /**
   * Tests that the resolver builds the default AI provider backend.
   */
  public function testResolverReturnsAiProviderBackend(): void {
    $prompt_config = $this->createMock(ImmutableConfig::class);
    $prompt_config->method('get')->willReturnMap([
      ['prompt', 'Test-Prompt'],
    ]);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturnMap([
      ['ai.ai_prompt.' . AttendanceRecognition::PROMPT_ID, $prompt_config],
    ]);

    $resolver = new OcrBackendResolver(
      $configFactory,
      $this->createProviderManager([
        'provider_id' => 'opencode_go',
        'model_id' => 'kimi-k3',
      ]),
    );

    self::assertInstanceOf(AiProviderVisionBackend::class, $resolver->resolve());
  }

  /**
   * Tests the clean error when the AI module is not installed.
   */
  public function testResolverWithoutAiModuleThrows(): void {
    $resolver = new OcrBackendResolver($this->createMock(ConfigFactoryInterface::class), NULL);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('The AI module is not available.');
    $resolver->resolve();
  }

  /**
   * Tests that free and logging models are recognized.
   */
  public function testIsTrainingModel(): void {
    self::assertTrue(AttendanceRecognition::isTrainingModel('some-free-model'));
    self::assertTrue(AttendanceRecognition::isTrainingModel('Big-Pickle-Preview'));
    self::assertFalse(AttendanceRecognition::isTrainingModel('gpt-4.1'));
  }

  /**
   * Creates an AI provider manager with a mocked default provider config.
   *
   * The manager is final, so it cannot be mocked. The real instance with a
   * mocked config factory exercises getDefaultProviderForOperationType().
   *
   * @param array{provider_id: string, model_id: string}|null $default
   *   The default provider of the image vision operation, or NULL.
   *
   * @return \Drupal\ai\AiProviderPluginManager
   *   The AI provider plugin manager.
   */
  private function createProviderManager(?array $default): AiProviderPluginManager {
    $config = new ImmutableConfig(
      'ai.settings',
      $this->createMock(StorageInterface::class),
      $this->createMock(EventDispatcherInterface::class),
      $this->createMock(TypedConfigManagerInterface::class),
    );
    $config->setData([
      'default_providers' => $default === NULL ? [] : [
        AttendanceRecognition::OPERATION_TYPE => $default,
      ],
    ]);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->with('ai.settings')->willReturn($config);

    $manager = (new \ReflectionClass(AiProviderPluginManager::class))->newInstanceWithoutConstructor();
    \assert($manager instanceof AiProviderPluginManager);
    $property = new \ReflectionProperty(AiProviderPluginManager::class, 'configFactory');
    $property->setValue($manager, $configFactory);

    return $manager;
  }

}
