<?php

declare(strict_types=1);

namespace Drupal\workspace_events;

use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Reads the location and link fields of course and event detail pages.
 */
final class DetailFieldResolver {

  /**
   * Returns the location term of a course or event.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The course or event node.
   *
   * @return \Drupal\taxonomy\TermInterface|null
   *   The location term, or NULL when the node has none.
   */
  public function getLocationTerm(NodeInterface $node): ?TermInterface {
    if (!$node->hasField('field_location')) {
      return NULL;
    }
    $term = $node->get('field_location')->entity;
    return $term instanceof TermInterface ? $term : NULL;
  }

  /**
   * Returns the address of a location term.
   *
   * @param \Drupal\taxonomy\TermInterface $term
   *   The location term.
   *
   * @return string
   *   The trimmed address, or an empty string when the term has none.
   */
  public function getAddress(TermInterface $term): string {
    if (!$term->hasField('field_address') || $term->get('field_address')->isEmpty()) {
      return '';
    }
    return trim($term->get('field_address')->getString());
  }

  /**
   * Returns the map URL of a location term.
   *
   * When the term has no valid map link but an address, the address search of
   * OpenStreetMap is used instead.
   *
   * @param \Drupal\taxonomy\TermInterface $term
   *   The location term.
   *
   * @return \Drupal\Core\Url|null
   *   The map URL, or NULL when the term has neither a map link nor an address.
   */
  public function getMapUrl(TermInterface $term): ?Url {
    if ($term->hasField('field_map_link') && !$term->get('field_map_link')->isEmpty()) {
      $uri = $term->get('field_map_link')->first()?->get('uri')->getString() ?? '';
      if ($uri !== '') {
        try {
          return Url::fromUri($uri);
        }
        catch (\InvalidArgumentException) {
          // Fall back to the address search below.
        }
      }
    }

    $address = $this->getAddress($term);
    if ($address === '') {
      return NULL;
    }
    $query = preg_replace('/\s*\R\s*/u', ', ', $address) ?? $address;
    return Url::fromUri('https://www.openstreetmap.org/search?query=' . rawurlencode($query));
  }

  /**
   * Returns the optional external link of a course or event.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The course or event node.
   *
   * @return array{url: \Drupal\Core\Url, title: string}|null
   *   The link and its trimmed title, or NULL when the node has no valid link.
   */
  public function getLink(NodeInterface $node): ?array {
    if (!$node->hasField('field_link') || $node->get('field_link')->isEmpty()) {
      return NULL;
    }
    $item = $node->get('field_link')->first();
    if ($item === NULL) {
      return NULL;
    }

    $uri = $item->get('uri')->getString();
    if ($uri === '') {
      return NULL;
    }
    try {
      $url = Url::fromUri($uri);
    }
    catch (\InvalidArgumentException) {
      return NULL;
    }

    return [
      'url' => $url,
      'title' => trim($item->get('title')->getString()),
    ];
  }

  /**
   * Returns the difficulty label of a course.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The course node.
   *
   * @return string
   *   The formatted level, or an empty string when the course has none.
   */
  public function getDifficultyLabel(NodeInterface $node): string {
    if (!$node->hasField('field_difficulty')) {
      return '';
    }
    $items = $node->get('field_difficulty');
    if ($items->isEmpty()) {
      return '';
    }

    $values = [];
    foreach ($items as $item) {
      $value = $item->get('value')->getString();
      if (is_numeric($value)) {
        $values[] = (int) $value;
      }
    }

    return Difficulty::label($values);
  }

}
