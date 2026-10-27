<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_events\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\workspace_events\Attendance\MemberMatcher;
use Drupal\workspace_events\Attendance\MemberNameLoaderInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the local member matching.
 */
#[CoversClass(MemberMatcher::class)]
#[Group('workspace_events')]
final class MemberMatcherTest extends UnitTestCase {

  /**
   * Tests an exact match.
   */
  public function testExactMatch(): void {
    $result = $this->createMatcher([7 => 'Max Mustermann'])->match(['Max Mustermann']);
    self::assertSame([7], $result['matched']);
    self::assertSame([], $result['unmatched']);
  }

  /**
   * Tests that umlauts and ß are transliterated on both sides.
   */
  public function testUmlautsAndSharpS(): void {
    $result = $this->createMatcher([
      3 => 'Jürgen Groß',
      4 => 'Bärbel Weiß',
    ])->match(['Juergen Gross', 'Baerbel Weiss']);
    self::assertSame([3, 4], $result['matched']);
    self::assertSame([], $result['unmatched']);
  }

  /**
   * Tests that the first and last name order does not matter.
   */
  public function testSwappedNames(): void {
    $result = $this->createMatcher([9 => 'Max Mustermann'])->match(['Mustermann, Max']);
    self::assertSame([9], $result['matched']);
  }

  /**
   * Tests a typo within a Levenshtein distance of two.
   */
  public function testTypoWithinDistance(): void {
    $result = $this->createMatcher([5 => 'Max Mustermann'])->match(['Max Mustermannn']);
    self::assertSame([5], $result['matched']);
    self::assertSame([], $result['unmatched']);
  }

  /**
   * Tests that a name that fits several members is not assigned.
   */
  public function testAmbiguousNameIsUnmatched(): void {
    $result = $this->createMatcher([
      1 => 'Anna Muster',
      2 => 'Anne Muster',
    ])->match(['Ann Muster']);
    self::assertSame([], $result['matched']);
    self::assertSame(['Ann Muster'], $result['unmatched']);
  }

  /**
   * Tests that an exact match wins over a similar member.
   */
  public function testExactMatchWinsOverSimilarName(): void {
    $result = $this->createMatcher([
      1 => 'Anna Muster',
      2 => 'Anne Muster',
    ])->match(['Anna Muster']);
    self::assertSame([1], $result['matched']);
    self::assertSame([], $result['unmatched']);
  }

  /**
   * Tests matching without any member.
   */
  public function testNoMembers(): void {
    $result = $this->createMatcher([])->match(['Max Mustermann']);
    self::assertSame([], $result['matched']);
    self::assertSame(['Max Mustermann'], $result['unmatched']);
  }

  /**
   * Tests that several lines for the same member produce one ID.
   */
  public function testDuplicateLinesProduceOneMatch(): void {
    $result = $this->createMatcher([4 => 'Max Mustermann'])->match([
      'Max Mustermann',
      'Mustermann Max',
    ]);
    self::assertSame([4], $result['matched']);
    self::assertSame([], $result['unmatched']);
  }

  /**
   * Tests that empty and noise lines are ignored.
   */
  public function testNoiseLinesAreIgnored(): void {
    $result = $this->createMatcher([4 => 'Max Mustermann'])->match(['', '  ', '12.10.2026']);
    self::assertSame([], $result['matched']);
    self::assertSame([], $result['unmatched']);
  }

  /**
   * Creates a matcher with the given member names.
   *
   * @param array<int, string> $members
   *   The member names keyed by user ID.
   *
   * @return \Drupal\workspace_events\Attendance\MemberMatcher
   *   The matcher.
   */
  private function createMatcher(array $members): MemberMatcher {
    $loader = new class($members) implements MemberNameLoaderInterface {

      /**
       * Constructs the test loader.
       *
       * @param array<int, string> $members
       *   The member names.
       */
      public function __construct(
        private readonly array $members,
      ) {}

      /**
       * {@inheritdoc}
       */
      public function load(): array {
        return $this->members;
      }

    };

    return new MemberMatcher($loader);
  }

}
