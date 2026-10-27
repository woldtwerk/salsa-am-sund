<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_events\Kernel;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Form\FormState;
use Drupal\node\NodeInterface;
use Drupal\workspace_events\Hook\LessonAccessHooks;
use Drupal\workspace_events\Plugin\Field\FieldWidget\LessonTableWidget;
use Drupal\workspace_events\SiteTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the read-only lesson table widget of the course form.
 */
#[CoversClass(LessonTableWidget::class)]
#[Group('workspace_events')]
#[RunTestsInSeparateProcesses]
final class LessonTableWidgetTest extends LessonKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installLessonConfig();
  }

  /**
   * Tests the table rows, the edit links and the unchanged references.
   */
  public function testLessonTable(): void {
    $now = new \DateTimeImmutable('2026-11-03 12:00:00', new \DateTimeZone(self::TIMEZONE));
    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentTime')->willReturn($now->getTimestamp());
    $time->method('getRequestTime')->willReturn($now->getTimestamp());
    $this->container->set('datetime.time', $time);
    $this->container->set(SiteTimeZone::class, new SiteTimeZone($this->container->get('config.factory'), $time));

    $editor = $this->createUser(['access content', LessonAccessHooks::EDIT_PERMISSION]);
    $non_editor = $this->createUser(['access content']);

    $cancelled = $this->createLesson([
      'title' => 'Ausgefallen',
      'field_lesson_date' => $this->dateRange(
        $now->modify('+1 week')->setTime(19, 0),
        $now->modify('+1 week')->setTime(20, 0),
      ),
      'field_cancelled' => TRUE,
    ]);
    $published = $this->createLesson([
      'title' => 'Regulär',
      'field_lesson_date' => $this->dateRange(
        $now->modify('+2 weeks')->setTime(19, 0),
        $now->modify('+2 weeks')->setTime(20, 0),
      ),
    ]);

    // Reference the later lesson first, so the widget has to sort by start.
    $course = $this->createCourse([
      'title' => 'Salsa Kurs',
      'field_lessons' => [
        ['target_id' => $published->id()],
        ['target_id' => $cancelled->id()],
      ],
    ]);
    $original_ids = $this->referencedLessonIds($course);

    $this->setCurrentUser($editor);
    $rows = $this->buildRows($course);
    self::assertCount(2, $rows);
    self::assertSame('Fällt aus', (string) $rows[0]['data'][2]);
    self::assertSame('Veröffentlicht', (string) $rows[1]['data'][2]);
    self::assertArrayHasKey('#url', $rows[0]['data'][5]['data']);
    self::assertArrayHasKey('#url', $rows[1]['data'][5]['data']);
    self::assertSame(
      $course->toUrl('edit-form')->toString(),
      $rows[0]['data'][5]['data']['#url']->getOption('query')['destination'],
    );

    // The cancelled lesson is the next one, but the widget skips it.
    $current_rows = array_keys(array_filter(
      $rows,
      static fn (array $row): bool => in_array('lesson-table__row--current', $row['class'] ?? [], TRUE),
    ));
    self::assertSame([1], $current_rows);

    // Rendering the table must not treat the link render array as attributes.
    $output = (string) \Drupal::service('renderer')->renderInIsolation($this->buildElement($course));
    self::assertStringContainsString($cancelled->toUrl('edit-form')->toString(), $output);

    $this->setCurrentUser($non_editor);
    $rows = $this->buildRows($course);
    self::assertCount(2, $rows);
    self::assertArrayNotHasKey('#url', $rows[0]['data'][5]['data']);
    self::assertArrayNotHasKey('#url', $rows[1]['data'][5]['data']);

    // Submitting the widget must keep the generated references unchanged.
    $form = ['#parents' => []];
    $this->widget($course)->extractFormValues($course->get('field_lessons'), $form, new FormState());
    self::assertSame($original_ids, $this->referencedLessonIds($course));
  }

  /**
   * Builds the widget element of a course.
   *
   * @param \Drupal\node\NodeInterface $course
   *   The course.
   *
   * @return array<string, mixed>
   *   The widget element.
   */
  private function buildElement(NodeInterface $course): array {
    $form = ['#parents' => []];
    return $this->widget($course)->form($course->get('field_lessons'), $form, $this->formState($course));
  }

  /**
   * Builds the widget and returns the rows of its table.
   *
   * @param \Drupal\node\NodeInterface $course
   *   The course.
   *
   * @return array<int, array<int, mixed>>
   *   The table rows.
   */
  private function buildRows(NodeInterface $course): array {
    $rows = $this->buildElement($course)['widget']['table']['#rows'] ?? NULL;
    self::assertIsArray($rows);
    return $rows;
  }

  /**
   * Creates a form state with the node form of the course.
   *
   * @param \Drupal\node\NodeInterface $course
   *   The course.
   *
   * @return \Drupal\Core\Form\FormState
   *   The form state.
   */
  private function formState(NodeInterface $course): FormState {
    $form_object = $this->container->get('entity_type.manager')->getFormObject('node', 'default');
    self::assertInstanceOf(EntityFormInterface::class, $form_object);
    $form_object->setEntity($course);

    $form_state = new FormState();
    $form_state->setFormObject($form_object);
    return $form_state;
  }

  /**
   * Returns the lesson table widget for a course.
   *
   * @param \Drupal\node\NodeInterface $course
   *   The course.
   *
   * @return \Drupal\workspace_events\Plugin\Field\FieldWidget\LessonTableWidget
   *   The widget.
   */
  private function widget(NodeInterface $course): LessonTableWidget {
    $manager = $this->container->get('plugin.manager.field.widget');
    $widget = $manager->createInstance('workspace_events_lesson_table', [
      'field_definition' => $course->getFieldDefinition('field_lessons'),
      'settings' => [],
      'third_party_settings' => [],
    ]);
    self::assertInstanceOf(LessonTableWidget::class, $widget);
    return $widget;
  }

  /**
   * Returns the referenced lesson IDs of a course.
   *
   * @param \Drupal\node\NodeInterface $course
   *   The course.
   *
   * @return int[]
   *   The lesson IDs.
   */
  private function referencedLessonIds(NodeInterface $course): array {
    $ids = [];
    foreach ($course->get('field_lessons') as $item) {
      $ids[] = (int) $item->get('target_id')->getString();
    }
    return $ids;
  }

}
