<?php

declare(strict_types=1);

namespace Drupal\workspace_events;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Builds schema.org JSON-LD for course and event detail pages.
 */
final class StructuredDataBuilder {

  /**
   * The schema.org context URL.
   */
  private const string CONTEXT = 'https://schema.org';

  /**
   * The course provider term, or NULL when it has not been loaded or missing.
   */
  private ?TermInterface $courseProvider = NULL;

  /**
   * Whether the course provider has already been looked up.
   */
  private bool $courseProviderLoaded = FALSE;

  /**
   * Constructs a StructuredDataBuilder.
   *
   * @param \Drupal\workspace_events\EventDescriptionFormatter $descriptionFormatter
   *   The event description formatter.
   * @param \Drupal\workspace_events\DetailFieldResolver $detailFieldResolver
   *   The reader of the location and link fields.
   * @param \Drupal\workspace_events\OrganizerResolver $organizerResolver
   *   The resolver of the fixed organizer terms.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    private readonly EventDescriptionFormatter $descriptionFormatter,
    private readonly DetailFieldResolver $detailFieldResolver,
    private readonly OrganizerResolver $organizerResolver,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Builds the structured data of an event page.
   *
   * Every upcoming occurrence is described as its own Event, so search engines
   * always get a concrete start and end date instead of a recurrence rule.
   * Past events have no structured data; the page is no longer about an
   * upcoming event.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The event node.
   * @param \Drupal\workspace_events\EventOccurrence[] $occurrences
   *   The upcoming occurrences.
   *
   * @return array<string, mixed>
   *   The JSON-LD data, or an empty array when there are no occurrences or the
   *   node has not been saved yet.
   */
  public function buildEvent(NodeInterface $node, array $occurrences): array {
    if ($node->isNew() || $occurrences === []) {
      return [];
    }

    $url = $this->getCanonicalUrl($node);
    $description = $this->descriptionFormatter->toPlainText($node);
    $link = $this->detailFieldResolver->getLink($node);
    $same_as = $link === NULL ? NULL : $link['url']->setAbsolute()->toString();
    $place = $this->buildPlace($node);
    $front_url = $this->getFrontUrl();

    $organizers = [];
    $events = [];
    foreach ($occurrences as $occurrence) {
      $event = [
        '@type' => 'Event',
        // A recurring event has one Event per date, so the start timestamp
        // keeps the identifiers unique.
        '@id' => $url . '#event-' . $occurrence->sourceKey() . '-' . $occurrence->start->getTimestamp(),
        'name' => (string) $node->label(),
        'url' => $url,
        'startDate' => $this->formatStart($occurrence),
        'eventStatus' => self::CONTEXT . '/EventScheduled',
        'eventAttendanceMode' => self::CONTEXT . '/OfflineEventAttendanceMode',
      ];
      $end = $this->formatEnd($occurrence);
      if ($end !== NULL) {
        $event['endDate'] = $end;
      }
      if ($description !== '') {
        $event['description'] = $description;
      }
      if ($same_as !== NULL) {
        $event['sameAs'] = $same_as;
      }
      if ($place !== NULL) {
        $event['location'] = $place;
      }
      $organizer = NULL;
      if ($occurrence->organizer !== NULL) {
        $tid = (int) $occurrence->organizer->id();
        $organizers[$tid] ??= $this->buildOrganizer($occurrence->organizer, $front_url);
        $organizer = $organizers[$tid];
      }
      if ($organizer !== NULL) {
        $event['organizer'] = $organizer;
      }
      $events[] = $event;
    }

    return [
      '@context' => self::CONTEXT,
      '@graph' => $events,
    ];
  }

  /**
   * Builds the structured data of a course page.
   *
   * A course is a series, so the page describes the course itself and one
   * CourseInstance per upcoming date.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The course node.
   * @param \Drupal\workspace_events\EventOccurrence[] $occurrences
   *   The upcoming occurrences.
   *
   * @return array<string, mixed>
   *   The JSON-LD data, or an empty array when the node has not been saved yet.
   */
  public function buildCourse(NodeInterface $node, array $occurrences): array {
    if ($node->isNew()) {
      return [];
    }

    $url = $this->getCanonicalUrl($node);
    $description = $this->descriptionFormatter->toPlainText($node);
    $place = $this->buildPlace($node);

    $course = [
      '@type' => 'Course',
      '@id' => $url . '#course',
      'name' => (string) $node->label(),
      'url' => $url,
    ];
    $provider = $this->buildCourseProvider();
    if ($provider !== NULL) {
      $course['provider'] = $provider;
    }
    if ($description !== '') {
      $course['description'] = $description;
    }

    $instances = [];
    foreach ($occurrences as $occurrence) {
      $instance = [
        '@type' => 'CourseInstance',
        'courseMode' => 'onsite',
        'startDate' => $this->formatStart($occurrence),
      ];
      $end = $this->formatEnd($occurrence);
      if ($end !== NULL) {
        $instance['endDate'] = $end;
      }
      if ($place !== NULL) {
        $instance['location'] = $place;
      }
      $instances[] = $instance;
    }
    if ($instances !== []) {
      $course['hasCourseInstance'] = $instances;
    }

    return [
      '@context' => self::CONTEXT,
      '@graph' => [$course],
    ];
  }

  /**
   * Returns the canonical absolute URL of a node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   *
   * @return string
   *   The absolute canonical URL.
   */
  private function getCanonicalUrl(NodeInterface $node): string {
    return $node->toUrl('canonical', ['absolute' => TRUE])->toString();
  }

  /**
   * Formats the start of an occurrence as an ISO 8601 value.
   *
   * @param \Drupal\workspace_events\EventOccurrence $occurrence
   *   The occurrence.
   *
   * @return string
   *   The date, or just the day for all-day events.
   */
  private function formatStart(EventOccurrence $occurrence): string {
    return $occurrence->allDay
      ? $occurrence->start->format('Y-m-d')
      : $occurrence->start->format(\DateTimeInterface::ATOM);
  }

  /**
   * Formats the end of an occurrence as an ISO 8601 value.
   *
   * @param \Drupal\workspace_events\EventOccurrence $occurrence
   *   The occurrence.
   *
   * @return string|null
   *   The end date, or NULL when the occurrence has no known end.
   */
  private function formatEnd(EventOccurrence $occurrence): ?string {
    if ($occurrence->end === NULL) {
      return NULL;
    }
    return $occurrence->allDay
      ? $occurrence->end->format('Y-m-d')
      : $occurrence->end->format(\DateTimeInterface::ATOM);
  }

  /**
   * Builds the location of a course or event.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The course or event node.
   *
   * @return array<string, mixed>|null
   *   The schema.org Place, or NULL when the node has no location.
   */
  private function buildPlace(NodeInterface $node): ?array {
    $term = $this->detailFieldResolver->getLocationTerm($node);
    if ($term === NULL) {
      return NULL;
    }

    $place = [
      '@type' => 'Place',
      'name' => (string) $term->label(),
    ];

    $address = $this->detailFieldResolver->getAddress($term);
    if ($address !== '') {
      $place['address'] = $this->buildPostalAddress($address);
    }

    $map_url = $this->detailFieldResolver->getMapUrl($term);
    if ($map_url !== NULL) {
      $place['hasMap'] = $map_url->setAbsolute()->toString();
    }

    return $place;
  }

  /**
   * Splits a free-form address into a schema.org PostalAddress.
   *
   * The addresses are entered as lines: one or more street lines followed by
   * the postal code and city. The last line is only treated as postal code and
   * city when it starts with digits, so a plain street address still works.
   *
   * @param string $address
   *   The address as entered on the location term.
   *
   * @return array<string, string>
   *   The schema.org PostalAddress.
   */
  private function buildPostalAddress(string $address): array {
    $lines = preg_split('/\R/u', $address) ?: [];
    $lines = array_values(array_filter(
      array_map(static fn (string $line): string => trim($line), $lines),
      static fn (string $line): bool => $line !== '',
    ));

    $postal_code = '';
    $locality = '';
    $last = $lines === [] ? '' : $lines[array_key_last($lines)];
    if (preg_match('/^([0-9]{4,10})\s+(.+)$/u', $last, $matches) === 1) {
      $postal_code = $matches[1];
      $locality = $matches[2];
      array_pop($lines);
    }

    $postal_address = ['@type' => 'PostalAddress'];
    $street = implode(', ', $lines);
    if ($street !== '') {
      $postal_address['streetAddress'] = $street;
    }
    if ($postal_code !== '') {
      $postal_address['postalCode'] = $postal_code;
    }
    if ($locality !== '') {
      $postal_address['addressLocality'] = $locality;
    }
    $postal_address['addressCountry'] = 'DE';

    return $postal_address;
  }

  /**
   * Builds the organizer of an event.
   *
   * @param \Drupal\taxonomy\TermInterface|null $organizer
   *   The organizer term, if any.
   * @param string $frontUrl
   *   The absolute URL of the front page.
   *
   * @return array<string, string>|null
   *   The schema.org Organization, or NULL when the event has no organizer.
   */
  private function buildOrganizer(?TermInterface $organizer, string $frontUrl): ?array {
    if ($organizer === NULL) {
      return NULL;
    }

    $data = [
      '@type' => 'Organization',
      'name' => (string) $organizer->label(),
    ];
    if ((string) $organizer->uuid() === OrganizerResolver::SALSA_AM_SUND_UUID) {
      $data['url'] = $frontUrl;
    }
    return $data;
  }

  /**
   * Returns the provider term of a course.
   *
   * The term is looked up at most once per request, so rendering several
   * courses does not query it again.
   *
   * @return \Drupal\taxonomy\TermInterface|null
   *   The "Salsa am Sund" organizer term, or NULL when it does not exist.
   */
  public function findCourseProvider(): ?TermInterface {
    if ($this->courseProviderLoaded) {
      return $this->courseProvider;
    }
    $this->courseProviderLoaded = TRUE;

    $tid = $this->organizerResolver->id(OrganizerResolver::SALSA_AM_SUND_UUID);
    if ($tid === NULL) {
      return NULL;
    }
    $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($tid);
    if ($term instanceof TermInterface) {
      $this->courseProvider = $term;
    }
    return $this->courseProvider;
  }

  /**
   * Builds the provider of a course.
   *
   * @return array<string, string>|null
   *   The schema.org Organization, or NULL when the provider term is missing.
   */
  private function buildCourseProvider(): ?array {
    $provider = $this->findCourseProvider();
    if ($provider === NULL) {
      return NULL;
    }
    return [
      '@type' => 'Organization',
      'name' => (string) $provider->label(),
      'url' => $this->getFrontUrl(),
    ];
  }

  /**
   * Returns the absolute URL of the front page.
   */
  private function getFrontUrl(): string {
    return Url::fromRoute('<front>', [], ['absolute' => TRUE])->toString();
  }

}
