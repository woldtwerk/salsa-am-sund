<?php

declare(strict_types=1);

namespace Drupal\workspace_events\Attendance;

/**
 * Splits the text returned by the vision model into candidate name lines.
 *
 * The parser only removes layout noise (headings, list markers, table
 * separators, dates and numbers). It does not decide whether a line is a
 * member name: lines that are not a name simply do not match in
 * MemberMatcher.
 */
final class AttendancePhotoTextParser {

  /**
   * Parses the model response into candidate lines.
   *
   * @param string $markdown
   *   The text returned by the vision model.
   *
   * @return string[]
   *   The candidate lines in document order, without duplicates.
   */
  public function parse(string $markdown): array {
    $lines = preg_split('/\R/u', $markdown);
    if ($lines === FALSE) {
      return [];
    }

    $candidates = [];
    foreach ($lines as $line) {
      foreach ($this->parseLine($line) as $candidate) {
        $candidates[$candidate] = $candidate;
      }
    }
    return array_values($candidates);
  }

  /**
   * Parses one line into candidate cells.
   *
   * @param string $line
   *   The markdown line.
   *
   * @return string[]
   *   The candidate cells of the line.
   */
  private function parseLine(string $line): array {
    $line = trim($line);
    if ($line === '') {
      return [];
    }

    // Drop table separator rows such as "| --- | :---: |".
    if (preg_match('/^\|?[\s:|-]+\|?$/u', $line) === 1) {
      return [];
    }

    // Strip ATX heading markers, keeping the heading text as a candidate. A
    // heading is not a member name, so it drops out in the matcher.
    $line = preg_replace('/^\s*#{1,6}\s*/u', '', $line) ?? $line;

    $cells = str_contains($line, '|')
      ? explode('|', trim($line, " \t|"))
      : [$line];

    $candidates = [];
    foreach ($cells as $cell) {
      $candidate = $this->clean($cell);
      if ($candidate !== '') {
        $candidates[] = $candidate;
      }
    }
    return $candidates;
  }

  /**
   * Removes layout noise from a single cell.
   *
   * @param string $value
   *   The cell value.
   *
   * @return string
   *   The cleaned candidate, or an empty string when nothing remains.
   */
  private function clean(string $value): string {
    // Strip list markers such as "-", "*", "•", "1." and "1)".
    $value = preg_replace('/^\s*(?:[-*+•]|\d+[.)])\s+/u', '', $value) ?? $value;
    // Remove markdown emphasis and inline-code markers.
    $value = str_replace(['**', '__', '*', '_', '`'], '', $value);
    // Remove dates (DD.MM.YYYY, DD/MM/YYYY, YYYY-MM-DD) and clock times.
    $value = preg_replace('/\b\d{1,4}[.\/-]\d{1,2}[.\/-]\d{1,4}\b/u', ' ', $value) ?? $value;
    $value = preg_replace('/\b\d{1,2}:\d{2}(?::\d{2})?\b/u', ' ', $value) ?? $value;
    // Remove standalone numbers, but keep digits inside words.
    $value = preg_replace('/(?<!\p{L})\d+(?!\p{L})/u', ' ', $value) ?? $value;
    // Trim leftover punctuation and collapse whitespace.
    $value = preg_replace('/^[\s\p{P}]+|[\s\p{P}]+$/u', '', $value) ?? $value;
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

    if ($value === '' || preg_match('/\p{L}/u', $value) !== 1) {
      return '';
    }
    return $value;
  }

}
