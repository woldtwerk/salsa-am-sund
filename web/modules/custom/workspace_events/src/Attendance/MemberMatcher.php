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
   * The OCR often returns first names only, so a name that does not match a
   * member's full name falls back to a token-wise match. A name that still
   * fits several members is ambiguous and reported with its candidates so the
   * editor can pick one. The member list is loaded once per request by the
   * loader.
   *
   * @param string[] $names
   *   The recognized candidate names.
   *
   * @return array{matched: int[], ambiguous: array<string, int[]>, unmatched: string[]}
   *   The matched user IDs, the candidates per ambiguous name (sorted by
   *   display name) and the names that fit no member.
   */
  public function match(array $names): array {
    $members = [];
    foreach ($this->memberNameLoader->load() as $id => $name) {
      $normalized = $this->normalize($name);
      if ($normalized !== '') {
        $members[(int) $id] = [
          'name' => $name,
          'normalized' => $normalized,
          'tokens' => preg_split('/\s+/u', $normalized) ?: [],
        ];
      }
    }

    $unmatched = [];
    $candidates = [];
    foreach ($names as $name) {
      $candidate = $this->normalize($name);
      if ($candidate === '') {
        continue;
      }
      $label = trim($name);

      $exact = [];
      $fuzzy = [];
      foreach ($members as $id => $member) {
        if ($candidate === $member['normalized']) {
          $exact[] = $id;
        }
        elseif ($this->distance($candidate, $member['normalized']) <= self::MAX_DISTANCE) {
          $fuzzy[] = $id;
        }
      }

      // An exact match wins over a merely similar member, so a typo in one
      // name cannot make an otherwise unambiguous line ambiguous.
      $hits = $exact === [] ? $fuzzy : $exact;
      if ($hits === []) {
        $hits = $this->partialMatches($candidate, $members);
      }
      if ($hits === []) {
        $unmatched[$label] = $label;
        continue;
      }

      $candidates[] = ['label' => $label, 'ids' => $hits];
    }

    $matched = [];
    $pending = [];
    foreach ($candidates as $candidate) {
      if (count($candidate['ids']) === 1) {
        $id = $candidate['ids'][0];
        $matched[$id] = $id;
        continue;
      }
      $pending[] = $candidate;
    }

    // A line that fits several members can become clear when another line of
    // the same photo names one of its candidates in full. Resolve repeatedly,
    // because one assignment can make the next line unique. A duplicate line
    // of an already matched member stays matched on purpose (the same person
    // just appears twice on the photo).
    do {
      $changed = FALSE;
      $remaining = [];
      foreach ($pending as $candidate) {
        $ids = array_values(array_diff($candidate['ids'], array_keys($matched)));
        if (count($ids) === 1) {
          $matched[$ids[0]] = $ids[0];
          $changed = TRUE;
          continue;
        }
        if ($ids === []) {
          $unmatched[$candidate['label']] = $candidate['label'];
          $changed = TRUE;
          continue;
        }
        $candidate['ids'] = $ids;
        $remaining[] = $candidate;
      }
      $pending = $remaining;
    } while ($changed && $pending !== []);

    $ambiguous = [];
    foreach ($pending as $candidate) {
      $ids = $candidate['ids'];
      usort($ids, static fn (int $a, int $b): int => strcasecmp($members[$a]['name'], $members[$b]['name']));
      $ambiguous[$candidate['label']] = $ids;
    }

    return [
      'matched' => array_values($matched),
      'ambiguous' => $ambiguous,
      'unmatched' => array_values($unmatched),
    ];
  }

  /**
   * Finds members whose name shares every token of a recognized name.
   *
   * Every token of the recognized name must match a distinct token of the
   * member's name. Single-letter tokens are treated as initials and match any
   * token starting with that letter; longer tokens must match exactly or, from
   * five characters on, within a Levenshtein distance of one.
   *
   * @param string $candidate
   *   The normalized recognized name.
   * @param array<int, array{name: string, normalized: string, tokens: string[]}> $members
   *   The members keyed by user ID.
   *
   * @return int[]
   *   The matching user IDs.
   */
  private function partialMatches(string $candidate, array $members): array {
    $tokens = preg_split('/\s+/u', $candidate) ?: [];
    $tokens = array_values(array_filter($tokens, static fn (string $token): bool => $token !== ''));
    if ($tokens === []) {
      return [];
    }
    // Match the full words first, so an initial is left for the remaining
    // name part instead of consuming a token that is also its own match.
    $words = array_values(array_filter($tokens, static fn (string $token): bool => mb_strlen($token) > 1));
    $initials = array_values(array_filter($tokens, static fn (string $token): bool => mb_strlen($token) === 1));

    $hits = [];
    foreach ($members as $id => $member) {
      if ($this->tokensMatch([...$words, ...$initials], $member['tokens'])) {
        $hits[] = $id;
      }
    }
    return $hits;
  }

  /**
   * Assigns every recognized token to a distinct token of a member name.
   *
   * @param string[] $tokens
   *   The remaining recognized tokens.
   * @param string[] $memberTokens
   *   The remaining tokens of the member name.
   *
   * @return bool
   *   TRUE when every recognized token can be assigned.
   */
  private function tokensMatch(array $tokens, array $memberTokens): bool {
    if ($tokens === []) {
      return TRUE;
    }
    $token = array_shift($tokens);
    foreach ($memberTokens as $index => $memberToken) {
      if (!$this->tokenMatches($token, $memberToken)) {
        continue;
      }
      $remaining = $memberTokens;
      unset($remaining[$index]);
      if ($this->tokensMatch($tokens, array_values($remaining))) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Checks whether one recognized token matches one member token.
   *
   * @param string $token
   *   The recognized token.
   * @param string $memberToken
   *   The member token.
   *
   * @return bool
   *   TRUE when the tokens match.
   */
  private function tokenMatches(string $token, string $memberToken): bool {
    if (mb_strlen($token) === 1) {
      return str_starts_with($memberToken, $token);
    }
    if ($token === $memberToken) {
      return TRUE;
    }
    return mb_strlen($token) >= 5 && $this->distance($token, $memberToken) <= 1;
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
