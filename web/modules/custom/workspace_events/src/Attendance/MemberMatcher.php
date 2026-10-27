<?php

declare(strict_types=1);

namespace Drupal\workspace_events\Attendance;

/**
 * Matches recognized names against the members of the site.
 */
final class MemberMatcher {

  /**
   * The maximum Levenshtein distance for a fuzzy match.
   */
  private const int MAX_DISTANCE = 2;

  /**
   * Constructs a MemberMatcher.
   *
   * @param \Drupal\workspace_events\Attendance\MemberNameLoaderInterface $memberNameLoader
   *   The loader of the member names.
   */
  public function __construct(
    private readonly MemberNameLoaderInterface $memberNameLoader,
  ) {}

  /**
   * Matches recognized names against the members.
   *
   * A name that matches several members is ambiguous and therefore reported
   * as unmatched. The member list is loaded once per request by the loader.
   *
   * @param string[] $names
   *   The recognized candidate names.
   *
   * @return array{matched: int[], unmatched: string[]}
   *   The matched user IDs and the names that could not be assigned clearly.
   */
  public function match(array $names): array {
    $members = [];
    foreach ($this->memberNameLoader->load() as $id => $name) {
      $normalized = $this->normalize($name);
      if ($normalized !== '') {
        $members[$id] = $normalized;
      }
    }

    $matched = [];
    $unmatched = [];
    foreach ($names as $name) {
      $candidate = $this->normalize($name);
      if ($candidate === '') {
        continue;
      }

      $exact = [];
      $fuzzy = [];
      foreach ($members as $id => $member) {
        if ($candidate === $member) {
          $exact[] = $id;
        }
        elseif ($this->distance($candidate, $member) <= self::MAX_DISTANCE) {
          $fuzzy[] = $id;
        }
      }

      // An exact match wins over a merely similar member, so a typo in one
      // name cannot make an otherwise unambiguous line ambiguous.
      $hits = $exact === [] ? $fuzzy : $exact;
      if (count($hits) === 1) {
        $matched[$hits[0]] = (int) $hits[0];
      }
      else {
        $unmatched[$candidate] = trim($name);
      }
    }

    return [
      'matched' => array_values($matched),
      'unmatched' => array_values($unmatched),
    ];
  }

  /**
   * Normalizes a name for comparison.
   *
   * Lower case, German umlauts and ß transliterated, punctuation removed and
   * the name parts sorted so that "Mustermann, Max" and "Max Mustermann" are
   * the same.
   *
   * @param string $name
   *   The name.
   *
   * @return string
   *   The normalized name.
   */
  private function normalize(string $name): string {
    $name = mb_strtolower(trim($name), 'UTF-8');
    $name = strtr($name, [
      'ä' => 'ae',
      'ö' => 'oe',
      'ü' => 'ue',
      'ß' => 'ss',
      'á' => 'a',
      'à' => 'a',
      'â' => 'a',
      'ã' => 'a',
      'å' => 'a',
      'ā' => 'a',
      'é' => 'e',
      'è' => 'e',
      'ê' => 'e',
      'ë' => 'e',
      'ē' => 'e',
      'í' => 'i',
      'ì' => 'i',
      'î' => 'i',
      'ï' => 'i',
      'ī' => 'i',
      'ó' => 'o',
      'ò' => 'o',
      'ô' => 'o',
      'õ' => 'o',
      'ø' => 'o',
      'ō' => 'o',
      'ú' => 'u',
      'ù' => 'u',
      'û' => 'u',
      'ū' => 'u',
      'ç' => 'c',
      'ñ' => 'n',
      'ý' => 'y',
      'ÿ' => 'y',
      'š' => 's',
      'ž' => 'z',
      'č' => 'c',
      'ř' => 'r',
    ]);

    $name = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $name) ?? '';
    if (preg_match('/\p{L}/u', $name) !== 1) {
      return '';
    }
    $parts = preg_split('/\s+/u', trim($name)) ?: [];
    sort($parts, SORT_STRING);
    return implode(' ', $parts);
  }

  /**
   * Calculates the Levenshtein distance of two normalized names.
   *
   * @param string $a
   *   The first name.
   * @param string $b
   *   The second name.
   *
   * @return int
   *   The distance.
   */
  private function distance(string $a, string $b): int {
    return levenshtein($a, $b);
  }

}
