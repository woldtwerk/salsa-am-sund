<?php

declare(strict_types=1);

namespace Drupal\workspace_events;

/**
 * Shared difficulty scale of the dance courses.
 */
final class Difficulty {

  /**
   * Returns the allowed difficulty values.
   *
   * Used as the allowed_values_function of the course difficulty field.
   *
   * @return array<int, string>
   *   The level labels, keyed by their value.
   */
  public static function allowedValues(): array {
    return [
      0 => 'Level 0',
      1 => 'Level 1',
      2 => 'Level 2',
      3 => 'Level 3',
    ];
  }

  /**
   * Formats difficulty values for display.
   *
   * A contiguous run of at least three levels is shown as a range, so
   * [1, 2, 3] becomes "Level 1–3". Everything else is joined with " + ".
   *
   * @param int[] $values
   *   The difficulty values.
   *
   * @return string
   *   The formatted label, or an empty string when there are no values.
   */
  public static function label(array $values): string {
    $values = array_values(array_unique($values));
    sort($values);
    if ($values === []) {
      return '';
    }

    $first = $values[0];
    $last = $values[count($values) - 1];
    if (count($values) >= 3 && $last - $first + 1 === count($values)) {
      return 'Level ' . $first . '–' . $last;
    }

    return 'Level ' . implode(' + ', array_map(
      static fn (int $value): string => (string) $value,
      $values,
    ));
  }

  /**
   * Parses a legacy free-text level value.
   *
   * Understands single levels ("Level 1"), sums ("Level 1 + 2") and ranges
   * with a hyphen or an en dash ("Level 1–3", "1-3"). Values outside the
   * allowed scale are dropped.
   *
   * @param string $legacy
   *   The legacy field value.
   *
   * @return int[]
   *   The sorted, unique difficulty values.
   */
  public static function parse(string $legacy): array {
    $normalized = preg_replace('/^\s*level\s*/i', '', trim($legacy)) ?? $legacy;
    $normalized = str_replace(['–', '—'], '-', $normalized);

    $values = [];
    foreach (preg_split('/\s*[+,]\s*/u', $normalized) ?: [] as $part) {
      $part = trim($part);
      if ($part === '') {
        continue;
      }

      if (preg_match('/^(\d+)\s*-\s*(\d+)$/', $part, $matches) === 1) {
        $start = (int) $matches[1];
        $end = (int) $matches[2];
        if ($start > $end) {
          [$start, $end] = [$end, $start];
        }
        for ($value = $start; $value <= $end; $value++) {
          if ($value >= 0 && $value <= 3) {
            $values[] = $value;
          }
        }
        continue;
      }

      if (preg_match('/^\d+$/', $part) === 1) {
        $value = (int) $part;
        if ($value >= 0 && $value <= 3) {
          $values[] = $value;
        }
      }
    }

    $values = array_values(array_unique($values));
    sort($values);
    return $values;
  }

}
