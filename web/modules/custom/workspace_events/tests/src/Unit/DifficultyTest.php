<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_events\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\workspace_events\Difficulty;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the shared difficulty scale.
 */
#[CoversClass(Difficulty::class)]
#[Group('workspace_events')]
final class DifficultyTest extends UnitTestCase {

  /**
   * Tests the display label of difficulty values.
   *
   * @param int[] $values
   *   The difficulty values.
   * @param string $expected
   *   The expected label.
   */
  #[DataProvider('labelProvider')]
  public function testLabel(array $values, string $expected): void {
    self::assertSame($expected, Difficulty::label($values));
  }

  /**
   * Provides difficulty values and the expected labels.
   *
   * @return iterable<string, array{0: int[], 1: string}>
   *   The test cases.
   */
  public static function labelProvider(): iterable {
    yield 'empty' => [[], ''];
    yield 'single level zero' => [[0], 'Level 0'];
    yield 'single level' => [[1], 'Level 1'];
    yield 'pair' => [[1, 2], 'Level 1 + 2'];
    yield 'unsorted pair' => [[2, 1], 'Level 1 + 2'];
    yield 'duplicates' => [[1, 1, 2], 'Level 1 + 2'];
    yield 'range of three' => [[1, 2, 3], 'Level 1–3'];
    yield 'unsorted range of three' => [[3, 1, 2], 'Level 1–3'];
    yield 'range of four' => [[0, 1, 2, 3], 'Level 0–3'];
    yield 'gap' => [[0, 2], 'Level 0 + 2'];
    yield 'range with a gap' => [[1, 2, 3, 5], 'Level 1 + 2 + 3 + 5'];
  }

  /**
   * Tests parsing legacy difficulty values.
   *
   * @param string $legacy
   *   The legacy field value.
   * @param int[] $expected
   *   The expected difficulty values.
   */
  #[DataProvider('parseProvider')]
  public function testParse(string $legacy, array $expected): void {
    self::assertSame($expected, Difficulty::parse($legacy));
  }

  /**
   * Provides legacy values and the expected difficulty values.
   *
   * @return iterable<string, array{0: string, 1: int[]}>
   *   The test cases.
   */
  public static function parseProvider(): iterable {
    yield 'empty' => ['', []];
    yield 'level zero' => ['Level 0', [0]];
    yield 'single level' => ['Level 1', [1]];
    yield 'sum' => ['Level 1 + 2', [1, 2]];
    yield 'en dash range' => ['Level 1–3', [1, 2, 3]];
    yield 'hyphen range' => ['Level 1-3', [1, 2, 3]];
    yield 'bare range' => ['1-3', [1, 2, 3]];
    yield 'spaced en dash range' => ['Level 1 – 3', [1, 2, 3]];
    yield 'out of range single value' => ['Level 4', []];
    yield 'out of range sum' => ['Level 1 + 5', [1]];
    yield 'out of range range' => ['Level 1–5', [1, 2, 3]];
    yield 'unsorted and duplicate values' => ['Level 2 + 1 + 2', [1, 2]];
    yield 'lowercase with spaces' => ['  level 2  ', [2]];
    yield 'negative value' => ['Level -1', []];
    yield 'unknown text' => ['Fortgeschritten', []];
    yield 'comma separated values' => ['Level 0, 2', [0, 2]];
    yield 'reversed range' => ['Level 3-1', [1, 2, 3]];
  }

}
