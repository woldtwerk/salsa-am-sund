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
    self::assertSame([
      ['name' => 'Max Mustermann', 'candidates' => [7], 'recommended' => 7],
    ], $this->createMatcher([7 => 'Max Mustermann'])->match(['Max Mustermann']));
  }

  /**
   * Tests that umlauts and ß are transliterated on both sides.
   */
  public function testUmlautsAndSharpS(): void {
    self::assertSame([
      ['name' => 'Juergen Gross', 'candidates' => [3], 'recommended' => 3],
      ['name' => 'Baerbel Weiss', 'candidates' => [4], 'recommended' => 4],
    ], $this->createMatcher([
      3 => 'Jürgen Groß',
      4 => 'Bärbel Weiß',
    ])->match(['Juergen Gross', 'Baerbel Weiss']));
  }

  /**
   * Tests that the first and last name order does not matter.
   */
  public function testSwappedNames(): void {
    self::assertSame([
      ['name' => 'Mustermann, Max', 'candidates' => [9], 'recommended' => 9],
    ], $this->createMatcher([9 => 'Max Mustermann'])->match(['Mustermann, Max']));
  }

  /**
   * Tests a typo within a Levenshtein distance of two.
   */
  public function testTypoWithinDistance(): void {
    self::assertSame([
      ['name' => 'Max Mustermannn', 'candidates' => [5], 'recommended' => 5],
    ], $this->createMatcher([5 => 'Max Mustermann'])->match(['Max Mustermannn']));
  }

  /**
   * Tests that a name that fits several members keeps its candidates.
   */
  public function testAmbiguousNameKeepsCandidates(): void {
    self::assertSame([
      ['name' => 'Ann Muster', 'candidates' => [1, 2], 'recommended' => NULL],
    ], $this->createMatcher([
      1 => 'Anna Muster',
      2 => 'Anne Muster',
    ])->match(['Ann Muster']));
  }

  /**
   * Tests that an exact match wins over a similar member.
   */
  public function testExactMatchWinsOverSimilarName(): void {
    self::assertSame([
      ['name' => 'Anna Muster', 'candidates' => [1], 'recommended' => 1],
    ], $this->createMatcher([
      1 => 'Anna Muster',
      2 => 'Anne Muster',
    ])->match(['Anna Muster']));
  }

  /**
   * Tests matching without any member.
   */
  public function testNoMembers(): void {
    self::assertSame([
      ['name' => 'Max Mustermann', 'candidates' => [], 'recommended' => NULL],
    ], $this->createMatcher([])->match(['Max Mustermann']));
  }

  /**
   * Tests that the same line produces one row only.
   */
  public function testDuplicateLinesProduceOneRow(): void {
    self::assertSame([
      ['name' => 'Max Mustermann', 'candidates' => [4], 'recommended' => 4],
    ], $this->createMatcher([4 => 'Max Mustermann'])->match([
      'Max Mustermann',
      'Mustermann Max',
    ]));
  }

  /**
   * Tests that empty and noise lines are ignored.
   */
  public function testNoiseLinesAreIgnored(): void {
    self::assertSame([], $this->createMatcher([4 => 'Max Mustermann'])->match(['', '  ', '12.10.2026']));
  }

  /**
   * Tests that a unique first name matches its member.
   */
  public function testUniqueFirstNameMatches(): void {
    self::assertSame([
      ['name' => 'Moritz', 'candidates' => [7], 'recommended' => 7],
    ], $this->createMatcher([7 => 'Moritz Müller'])->match(['Moritz']));
  }

  /**
   * Tests that a first name shared by two members is recommended by no one.
   */
  public function testSharedFirstNameStaysAmbiguous(): void {
    // The loader order is reversed on purpose: the candidates have to be
    // sorted by display name.
    self::assertSame([
      ['name' => 'Sophie', 'candidates' => [1, 2], 'recommended' => NULL],
    ], $this->createMatcher([
      2 => 'Sophie Weber',
      1 => 'Sophie Schulz',
    ])->match(['Sophie']));
  }

  /**
   * Tests that a full name removes its member from another line's candidates.
   */
  public function testAlreadyRecommendedMemberIsRemovedFromCandidates(): void {
    self::assertSame([
      ['name' => 'Sophie', 'candidates' => [2], 'recommended' => 2],
      ['name' => 'Sophie Schulz', 'candidates' => [1], 'recommended' => 1],
    ], $this->createMatcher([
      1 => 'Sophie Schulz',
      2 => 'Sophie Weber',
    ])->match(['Sophie', 'Sophie Schulz']));
  }

  /**
   * Tests that a last name matches its member.
   */
  public function testLastNameMatches(): void {
    self::assertSame([
      ['name' => 'Müller', 'candidates' => [7], 'recommended' => 7],
    ], $this->createMatcher([7 => 'Moritz Müller'])->match(['Müller']));
  }

  /**
   * Tests that an initial matches the member whose other name starts with it.
   */
  public function testInitialMatches(): void {
    self::assertSame([
      ['name' => 'Sophie S.', 'candidates' => [1], 'recommended' => 1],
    ], $this->createMatcher([
      1 => 'Sophie Schulz',
      2 => 'Sophie Weber',
    ])->match(['Sophie S.']));
  }

  /**
   * Tests that an unknown first name has no candidates.
   */
  public function testUnknownNameHasNoCandidates(): void {
    self::assertSame([
      ['name' => 'Nils', 'candidates' => [], 'recommended' => NULL],
    ], $this->createMatcher([7 => 'Moritz Müller'])->match(['Nils']));
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
