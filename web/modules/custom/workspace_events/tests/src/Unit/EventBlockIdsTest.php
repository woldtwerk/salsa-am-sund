<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_events\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\text\TextSummary;
use Drupal\workspace_events\DetailFieldResolver;
use Drupal\workspace_events\EventDescriptionFormatter;
use Drupal\workspace_events\EventOccurrenceRepository;
use Drupal\workspace_events\Plugin\Block\EventBlockBase;
use Drupal\workspace_events\SiteTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the ID parsing of the event block configuration.
 */
#[CoversClass(EventBlockBase::class)]
#[Group('workspace_events')]
final class EventBlockIdsTest extends UnitTestCase {

  /**
   * Tests the submitted checkbox values are reduced to positive term IDs.
   *
   * @param mixed $value
   *   The submitted form value.
   * @param int[] $expected
   *   The expected term IDs.
   */
  #[DataProvider('submittedProvider')]
  public function testGetSubmittedIds(mixed $value, array $expected): void {
    self::assertSame($expected, $this->block([])->submittedIds($value));
  }

  /**
   * Provides submitted checkbox values and the expected term IDs.
   *
   * @return iterable<string, array{0: mixed, 1: int[]}>
   *   The test cases.
   */
  public static function submittedProvider(): iterable {
    yield 'checked and unchecked checkboxes' => [[
      11 => '11',
      12 => 0,
      13 => '0',
    ], [11]];
    yield 'string and integer values' => [[
      11 => 11,
      12 => '12',
    ], [11, 12]];
    yield 'invalid values are ignored' => [[
      11 => 'abc',
      12 => -3,
      13 => NULL,
      14 => [15],
      16 => 16,
    ], [16]];
    yield 'missing value' => [NULL, []];
    yield 'not an array' => ['11', []];
  }

  /**
   * Tests the configured values are reduced to unique positive term IDs.
   *
   * @param mixed $configured
   *   The configured value.
   * @param int[] $expected
   *   The expected term IDs.
   */
  #[DataProvider('configuredProvider')]
  public function testGetConfiguredIds(mixed $configured, array $expected): void {
    $block = $this->block(['locations' => $configured]);
    self::assertSame($expected, $block->configuredIds('locations'));
  }

  /**
   * Provides configured values and the expected term IDs.
   *
   * @return iterable<string, array{0: mixed, 1: int[]}>
   *   The test cases.
   */
  public static function configuredProvider(): iterable {
    yield 'integers and digit strings' => [[11, '12'], [11, 12]];
    yield 'duplicates are removed' => [[11, '11', '011'], [11]];
    yield 'invalid values are ignored' => [[0, -3, 'abc', 1.5, TRUE, NULL], []];
    yield 'missing configuration' => [NULL, []];
    yield 'not a list' => ['11', []];
  }

  /**
   * Tests that an unknown configuration key has no IDs.
   */
  public function testGetConfiguredIdsWithoutKey(): void {
    self::assertSame([], $this->block([])->configuredIds('locations'));
  }

  /**
   * Builds a block test double.
   *
   * @param array<string, mixed> $configuration
   *   The block configuration.
   *
   * @return \Drupal\workspace_events\Plugin\Block\EventBlockBase
   *   The test block.
   */
  private function block(array $configuration): EventBlockBase {
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $repository = new EventOccurrenceRepository($entity_type_manager, $this->siteTimeZone());
    $formatter = new EventDescriptionFormatter(
      $this->createMock(RendererInterface::class),
      new TextSummary($entity_type_manager),
    );

    return new class($configuration, 'test', ['provider' => 'workspace_events'], $repository, $entity_type_manager, $formatter, new DetailFieldResolver()) extends EventBlockBase {

      /**
       * {@inheritdoc}
       */
      public function build(): array {
        return [];
      }

      /**
       * Exposes getSubmittedIds().
       *
       * @param mixed $value
       *   The submitted form value.
       *
       * @return int[]
       *   The parsed term IDs.
       */
      public function submittedIds(mixed $value): array {
        return $this->getSubmittedIds($value);
      }

      /**
       * Exposes getConfiguredIds().
       *
       * @param string $key
       *   The configuration key.
       *
       * @return int[]
       *   The parsed term IDs.
       */
      public function configuredIds(string $key): array {
        return $this->getConfiguredIds($key);
      }

    };
  }

  /**
   * Builds the site timezone service for the test.
   *
   * @return \Drupal\workspace_events\SiteTimeZone
   *   The timezone service.
   */
  private function siteTimeZone(): SiteTimeZone {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->with('timezone.default')->willReturn('UTC');
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')->with('system.date')->willReturn($config);
    return new SiteTimeZone($config_factory);
  }

}
