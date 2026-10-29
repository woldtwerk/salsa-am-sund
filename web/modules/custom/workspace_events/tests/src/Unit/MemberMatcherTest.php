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
    self::assertSame([], $result['ambiguous']);
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
    self::assertSame([], $result['ambiguous']);
    self::assertSame([], $result['unmatched']);
  }

  /**
   * Tests that the first and last name order does not matter.
   */
  public function testSwappedNames(): void {
    $result = $this->createMatcher([9 => 'Max Mustermann'])->match(['Mustermann, Max']);
    self::assertSame([9], $result['matched']);
    self::assertSame([], $result['ambiguous']);
  }

  /**
   * Tests a typo within a Levenshtein distance of two.
   */
  public function testTypoWithinDistance(): void {
    $result = $this->createMatcher([5 => 'Max Mustermann'])->match(['Max Mustermannn']);
    self::assertSame([5], $result['matched']);
    self::assertSame([], $result['ambiguous']);
    self::assertSame([], $result['unmatched']);
  }

  /**
   * Tests that a name that fits several members is reported as ambiguous.
   */
  public function testAmbiguousNameIsReported(): void {
    $result = $this->createMatcher([
      1 => 'Anna Muster',
      2 => 'Anne Muster',
    ])->match(['Ann Muster']);
    self::assertSame([], $result['matched']);
    self::assertSame(['Ann Muster' => [1, 2]], $result['ambiguous']);
    self::assertSame([], $result['unmatched']);
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
    self::assertSame([], $result['ambiguous']);
    self::assertSame([], $result['unmatched']);
  }

  /**
   * Tests matching without any member.
   */
  public function testNoMembers(): void {
    $result = $this->createMatcher([])->match(['Max Mustermann']);
    self::assertSame([], $result['matched']);
    self::assertSame([], $result['ambiguous']);
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
    self::assertSame([], $result['ambiguous']);
    self::assertSame([], $result['unmatched']);
  }

  /**
   * Tests that empty and noise lines are ignored.
   */
  public function testNoiseLinesAreIgnored(): void {
    $result = $this->createMatcher([4 => 'Max Mustermann'])->match(['', '  ', '12.10.2026']);
    self::assertSame([], $result['matched']);
    self::assertSame([], $result['ambiguous']);
    self::assertSame([], $result['unmatched']);
  }

  /**
   * Tests that a unique first name matches its member.
   */
  public function testUniqueFirstNameMatches(): void {
    $result = $this->createMatcher([7 => 'Moritz Müller'])->match(['Moritz']);
    self::assertSame([7], $result['matched']);
    self::assertSame([], $result['ambiguous']);
    self::assertSame([], $result['unmatched']);
  }

  /**
   * Tests that a first name shared by two members is ambiguous.
   */
  public function testSharedFirstNameIsAmbiguous(): void {
    // The loader order is reversed on purpose: the candidates have to be
    // sorted by display name.
    $result = $this->createMatcher([
      2 => 'Sophie Weber',
      1 => 'Sophie Schulz',
    ])->match(['Sophie']);
    self::assertSame([], $result['matched']);
    self::assertSame(['Sophie' => [1, 2]], $result['ambiguous']);
    self::assertSame([], $result['unmatched']);
  }

  /**
   * Tests that a full name removes its member from another line's candidates.
   */
  public function testAlreadyMatchedMemberIsRemovedFromCandidates(): void {
    $result = $this->createMatcher([
      1 => 'Sophie Schulz',
      2 => 'Sophie Weber',
    ])->match(['Sophie', 'Sophie Schulz']);
    self::assertSame([1, 2], $result['matched']);
    self::assertSame([], $result['ambiguous']);
    self::assertSame([], $result['unmatched']);
  }

  /**
   * Tests that a last name matches its member.
   */
  public function testLastNameMatches(): void {
    $result = $this->createMatcher([7 => 'Moritz Müller'])->match(['Müller']);
    self::assertSame([7], $result['matched']);
    self::assertSame([], $result['ambiguous']);
    self::assertSame([], $result['unmatched']);
  }

  /**
   * Tests that an initial matches the member whose other name starts with it.
   */
  public function testInitialMatches(): void {
    $result = $this->createMatcher([
      1 => 'Sophie Schulz',
      2 => 'Sophie Weber',
    ])->match(['Sophie S.']);
    self::assertSame([1], $result['matched']);
    self::assertSame([], $result['ambiguous']);
    self::assertSame([], $result['unmatched']);
  }

  /**
   * Tests that an unknown first name is unmatched.
   */
  public function testUnknownNameIsUnmatched(): void {
    $result = $this->createMatcher([7 => 'Moritz Müller'])->match(['Nils']);
    self::assertSame([], $result['matched']);
    self::assertSame([], $result['ambiguous']);
    self::assertSame(['Nils'], $result['unmatched']);
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
