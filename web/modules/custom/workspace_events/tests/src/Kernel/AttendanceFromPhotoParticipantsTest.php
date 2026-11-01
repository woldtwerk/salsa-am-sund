<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_events\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\field\Entity\FieldConfig;
use Drupal\field_widget_actions\FieldWidgetActionManagerInterface;
use Drupal\tagify_user_list\Element\EntityAutocompleteTagifyUserList;
use Drupal\workspace_events\AttendanceRecognition;
use Drupal\workspace_events\Form\LessonForm;
use Drupal\workspace_events\Plugin\FieldWidgetAction\AttendanceFromPhotoParticipants;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests applying recognized participants to a tagify participant widget.
 */
#[CoversClass(AttendanceFromPhotoParticipants::class)]
#[Group('workspace_events')]
#[RunTestsInSeparateProcesses]
final class AttendanceFromPhotoParticipantsTest extends LessonKernelTestBase {

  /**
   * The modules the tagify participant widget needs.
   *
   * @var string[]
   */
  protected static $modules = [
    'field_widget_actions',
    'token',
    'tagify',
    'tagify_user_list',
  ];

  /**
   * Tests that applying the review keeps existing and recognized members.
   *
   * The lesson form uses the tagify user list widget, which submits one JSON
   * string instead of the core tags "Name (ID)" string. The action has to
   * write input that this widget turns into the merged participants on save.
   */
  public function testApplyReviewMergesTagifyParticipants(): void {
    $this->setUpCurrentUser([], ['access content', AttendanceRecognition::PERMISSION]);
    $alice = $this->createUser([], 'Alice Mitglied');
    $bob = $this->createUser([], 'Bob Mitglied');
    $alice_id = (int) $alice->id();
    $bob_id = (int) $bob->id();

    $course = $this->createCourse(['title' => 'Salsa Kurs']);
    $season = $this->createSeason($course, ['title' => 'Saison']);
    $lesson = $this->lessonStorage->create([
      'title' => 'Stunde',
      'season' => $season->id(),
      'field_lesson_date' => $this->dateRange(
        $this->date('2026-09-07', '18:00'),
        $this->date('2026-09-07', '19:00'),
      ),
      'field_participants' => [$alice_id],
    ]);

    $display = $this->container->get('entity_display.repository')
      ->getFormDisplay('lesson', 'lesson', 'default');
    $display->setComponent('field_participants', [
      'type' => 'tagify_user_list_entity_reference_autocomplete_widget',
      'weight' => 0,
      'region' => 'content',
      'settings' => [
        'match_operator' => 'CONTAINS',
        'match_limit' => 10,
        'suggestions_dropdown' => 1,
        'placeholder' => '',
        'image' => 'user_picture',
        'image_style' => 'thumbnail',
        'show_info_label' => 1,
        'info_label' => '[user:mail]',
        'show_entity_id' => 0,
        'parent_selection' => 1,
      ],
    ]);
    $display->save();
    $widget = $display->getRenderer('field_participants');
    self::assertNotNull($widget);
    self::assertSame('tagify_user_list_entity_reference_autocomplete_widget', $widget->getPluginId());

    $field = FieldConfig::loadByName('lesson', 'lesson', 'field_participants');
    self::assertInstanceOf(FieldConfig::class, $field);

    // The tagify input of the existing participant, as the browser submits it.
    $existing_input = json_encode([
      [
        'value' => $alice_id,
        'entity_id' => $alice_id,
        'label' => $alice->getDisplayName(),
      ],
    ], JSON_THROW_ON_ERROR);
    $review = [
      'table' => [
        0 => [
          'mitglied' => [['target_id' => $bob_id]],
        ],
      ],
      'apply' => '',
    ];

    $form = ['#parents' => []];
    $form_object = LessonForm::create($this->container);
    self::assertInstanceOf(LessonForm::class, $form_object);
    $form_object->setEntity($lesson);

    $form_state = new FormState();
    $form_state->setFormObject($form_object);
    $form_state->set('form_display', $display);
    $form_state->setUserInput([
      'field_participants' => $existing_input,
      'attendance_review' => $review,
    ]);
    $form_state->setValue('field_participants', $existing_input);
    $form_state->setValue('attendance_review', $review);
    $form_state->setTriggeringElement([
      '#field_widget_action_field_name' => 'field_participants',
      '#parents' => ['attendance_review', 'apply'],
    ]);

    $manager = $this->container->get('plugin.manager.field_widget_actions');
    self::assertInstanceOf(FieldWidgetActionManagerInterface::class, $manager);
    $action = $manager->createInstance('attendance_from_photo_participants', ['multiple' => FALSE]);
    self::assertInstanceOf(AttendanceFromPhotoParticipants::class, $action);
    $action->setFieldDefinition($field);
    $action->setWidget($widget);
    $action->applyReviewSubmit($form, $form_state);

    // The action writes the programmatic entity reference values, which the
    // tagify element value callback turns into the JSON the browser renders.
    $input = $form_state->getUserInput()['field_participants'];
    self::assertIsArray($input);
    self::assertSame([$alice_id, $bob_id], $this->targetIds($input));

    $element = $widget->formElement($lesson->get('field_participants'), 0, [], $form, $form_state);
    $json = EntityAutocompleteTagifyUserList::valueCallback($element, $input, $form_state);
    self::assertIsString($json);
    $decoded = json_decode($json, TRUE, flags: JSON_THROW_ON_ERROR);
    self::assertIsArray($decoded);
    self::assertSame([$alice_id, $bob_id], array_map('intval', array_column($decoded, 'entity_id')));

    // The rebuilt widget saves the merged participants, not only the new ones.
    self::assertSame([$alice_id, $bob_id], $this->targetIds($widget->massageFormValues($json, $form, $form_state)));
  }

  /**
   * Extracts the target IDs from entity reference field values.
   *
   * @param array<mixed> $values
   *   The field values or decoded tagify input.
   *
   * @return int[]
   *   The target IDs, sorted ascending.
   */
  private function targetIds(array $values): array {
    $ids = [];
    foreach ($values as $value) {
      if (is_array($value) && isset($value['target_id'])) {
        $ids[] = (int) $value['target_id'];
      }
    }
    sort($ids);
    return $ids;
  }

}
