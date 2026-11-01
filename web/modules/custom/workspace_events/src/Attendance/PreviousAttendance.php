<?php

declare(strict_types=1);

namespace Drupal\workspace_events\Attendance;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\workspace_events\Entity\LessonInterface;

/**
 * Recommends members by the attendance of the earlier lessons of the course.
 *
 * When a recognized name fits several members, the previous attendance of the
 * same course tells which of them is most likely on the photo. Members who
 * come regularly win first; otherwise the most recent lesson that only one of
 * the candidates attended decides.
 */
final class PreviousAttendance {

  /**
   * The number of recent lessons that make a member a regular attendee.
   */
  private const int REGULAR_LESSONS = 5;

  /**
   * The maximum number of earlier lessons to look at.
   */
  private const int MAX_LESSONS = 10;

  /**
   * The participant IDs of the earlier lessons, keyed by lesson ID.
   *
   * @var array<int, int[][]>
   */
  private array $attendance = [];

  /**
   * Constructs a PreviousAttendance.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Recommends one or more of the candidates by the earlier lessons.
   *
   * @param \Drupal\workspace_events\Entity\LessonInterface $lesson
   *   The lesson whose attendance is being recorded.
   * @param int[] $candidates
   *   The user IDs that the recognized name fits.
   *
   * @return int[]
   *   The recommended user IDs, empty when the earlier lessons do not tell.
   */
  public function recommendAll(LessonInterface $lesson, array $candidates): array {
    return $this->recommendation($lesson, $candidates)['recommended'];
  }

  /**
   * Recommends one or more of the candidates and tells why.
   *
   * First every candidate who took part in at least half of the last five
   * earlier lessons is recommended, because regular attendees often share a
   * first name. Only when nobody is regular does the most recent earlier
   * lesson count in which exactly one of the candidates took part.
   *
   * @param \Drupal\workspace_events\Entity\LessonInterface $lesson
   *   The lesson whose attendance is being recorded.
   * @param int[] $candidates
   *   The user IDs that the recognized name fits.
   *
   * @return array{recommended: int[], reason: 'regular'|'previous'|null}
   *   The recommended user IDs and why they are recommended: because they
   *   attend regularly, because they attended the last lesson alone, or not at
   *   all (NULL on an empty recommendation).
   */
  public function recommendation(LessonInterface $lesson, array $candidates): array {
    $empty = ['recommended' => [], 'reason' => NULL];
    $candidates = array_values(array_unique(array_map('intval', $candidates)));
    if ($candidates === [] || $lesson->isNew() || !$lesson->hasField('field_lesson_date')) {
      return $empty;
    }

    $start = $lesson->get('field_lesson_date')->getString();
    if ($start === '') {
      return $empty;
    }

    $attendance = $this->attendance($lesson, $start);
    $recent = array_slice($attendance, 0, self::REGULAR_LESSONS);

    $wanted = array_flip($candidates);
    $regular = [];
    if ($recent !== []) {
      $expected = (int) ceil(count($recent) / 2);
      $counts = [];
      foreach ($recent as $participants) {
        foreach ($participants as $id) {
          if (isset($wanted[$id])) {
            $counts[$id] = ($counts[$id] ?? 0) + 1;
          }
        }
      }
      foreach ($candidates as $id) {
        if (($counts[$id] ?? 0) >= $expected) {
          $regular[] = $id;
        }
      }
      if ($regular !== []) {
        return ['recommended' => $regular, 'reason' => 'regular'];
      }
    }

    foreach ($attendance as $participants) {
      $attended = array_values(array_filter(
        $participants,
        static fn (int $id): bool => isset($wanted[$id]),
      ));
      if (count($attended) === 1) {
        return ['recommended' => [$attended[0]], 'reason' => 'previous'];
      }
    }

    return $empty;
  }

  /**
   * Returns the participants of the earlier lessons, newest first.
   *
   * The course is resolved through the season of the lesson. One entity query
   * finds the earlier lessons of all seasons of the course; the result is
   * memoized for the request.
   *
   * @param \Drupal\workspace_events\Entity\LessonInterface $lesson
   *   The lesson being edited.
   * @param string $start
   *   The storage value of the lesson's start date.
   *
   * @return int[][]
   *   The participant IDs per earlier lesson, newest lesson first.
   */
  private function attendance(LessonInterface $lesson, string $start): array {
    $lessonId = (int) $lesson->id();
    if (isset($this->attendance[$lessonId])) {
      return $this->attendance[$lessonId];
    }

    $course = $lesson->getCourse();
    if ($course === NULL) {
      return $this->attendance[$lessonId] = [];
    }

    $storage = $this->entityTypeManager->getStorage('lesson');
    $previousIds = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('season.entity:course_season.course', $course->id())
      ->condition('id', $lessonId, '<>')
      ->condition('field_lesson_date.value', $start, '<')
      ->sort('field_lesson_date.value', 'DESC')
      ->sort('id', 'DESC')
      ->range(0, self::MAX_LESSONS)
      ->execute();

    $attendance = [];
    foreach ($storage->loadMultiple($previousIds) as $previous) {
      if (!$previous instanceof LessonInterface || !$previous->hasField('field_participants')) {
        continue;
      }
      $participants = [];
      foreach ($previous->get('field_participants') as $item) {
        $id = (int) $item->get('target_id')->getString();
        if ($id > 0) {
          $participants[$id] = $id;
        }
      }
      $attendance[] = array_values($participants);
    }

    return $this->attendance[$lessonId] = $attendance;
  }

}
