<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_events\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\workspace_events\Attendance\AttendancePhotoTextParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the parsing of the OCR markdown.
 */
#[CoversClass(AttendancePhotoTextParser::class)]
#[Group('workspace_events')]
final class AttendancePhotoTextParserTest extends UnitTestCase {

  /**
   * Tests parsing OCR markdown into candidate lines.
   *
   * @param string $markdown
   *   The OCR markdown.
   * @param string[] $expected
   *   The expected candidate lines.
   */
  #[DataProvider('markdownProvider')]
  public function testParse(string $markdown, array $expected): void {
    self::assertSame($expected, (new AttendancePhotoTextParser())->parse($markdown));
  }

  /**
   * Provides OCR markdown and the expected candidate lines.
   *
   * @return iterable<string, array{0: string, 1: string[]}>
   *   The test cases.
   */
  public static function markdownProvider(): iterable {
    yield 'bullets' => [
      "- Max Mustermann\n- Erika Musterfrau",
      ['Max Mustermann', 'Erika Musterfrau'],
    ];
    yield 'numbered list and headings' => [
      "# Anwesenheit\n1. Max Mustermann\n2) Erika Musterfrau",
      ['Anwesenheit', 'Max Mustermann', 'Erika Musterfrau'],
    ];
    yield 'table cells' => [
      "| Name | Anwesend |\n| --- | --- |\n| Max Mustermann | ja |",
      ['Name', 'Anwesend', 'Max Mustermann', 'ja'],
    ];
    yield 'dates and numbers are removed' => [
      "26.10.2026\nMax Mustermann 12:34\nAnwesend: 3",
      ['Max Mustermann', 'Anwesend'],
    ];
    yield 'emphasis is removed' => [
      "- **Max Mustermann**\n- _Erika Musterfrau_",
      ['Max Mustermann', 'Erika Musterfrau'],
    ];
    yield 'duplicates are removed' => [
      "- Max Mustermann\n- Max Mustermann",
      ['Max Mustermann'],
    ];
    yield 'empty response' => [
      '',
      [],
    ];
    yield 'only noise' => [
      "---\n#\n2026-10-26\n42",
      [],
    ];
  }

}
