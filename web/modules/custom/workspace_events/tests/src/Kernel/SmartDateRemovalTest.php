<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_events\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\NodeType;
use Drupal\smart_date_recur\Entity\SmartDateOverride;
use Drupal\smart_date_recur\Entity\SmartDateRule;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that update 10010 only removes the legacy field and course rules.
 */
#[Group('workspace_events')]
#[RunTestsInSeparateProcesses]
final class SmartDateRemovalTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'node',
    'datetime',
    'options',
    'smart_date',
    'smart_date_recur',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installSchema('user', ['users_data']);
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['node']);
    $this->installEntitySchema('smart_date_rule');
    $this->installEntitySchema('smart_date_override');

    NodeType::create([
      'type' => 'event',
      'name' => 'Termin',
    ])->save();
    NodeType::create([
      'type' => 'course',
      'name' => 'Kurs',
    ])->save();

    $this->createDateField('field_when', ['event', 'course']);
    $this->createDateField('field_event_date', ['event']);
  }

  /**
   * Tests the update hook keeps the event data and the Smart Date modules.
   */
  public function testUpdateRemovesLegacyFieldOnly(): void {
    $course_rule = $this->createRule('course', 'field_when');
    $course_override = $this->createOverride((int) $course_rule->id());
    $event_rule = $this->createRule('event', 'field_event_date');
    $event_override = $this->createOverride((int) $event_rule->id());

    require_once dirname(__DIR__, 3) . '/workspace_events.install';
    workspace_events_update_10010();

    self::assertNull(FieldStorageConfig::loadByName('node', 'field_when'));
    self::assertNull(FieldConfig::loadByName('node', 'event', 'field_when'));
    self::assertNull(FieldConfig::loadByName('node', 'course', 'field_when'));

    self::assertTrue(\Drupal::moduleHandler()->moduleExists('smart_date'));
    self::assertTrue(\Drupal::moduleHandler()->moduleExists('smart_date_recur'));

    // Only the rules of the removed course field are gone.
    self::assertNull(SmartDateRule::load($course_rule->id()));
    self::assertNull(SmartDateOverride::load($course_override->id()));
    self::assertNotNull(SmartDateRule::load($event_rule->id()));
    self::assertNotNull(SmartDateOverride::load($event_override->id()));
  }

  /**
   * Creates a Smart Date field storage and instances.
   *
   * @param string $field_name
   *   The field name.
   * @param string[] $bundles
   *   The node bundles that get an instance.
   */
  private function createDateField(string $field_name, array $bundles): void {
    $storage = FieldStorageConfig::create([
      'field_name' => $field_name,
      'entity_type' => 'node',
      'type' => 'smartdate',
      'cardinality' => FieldStorageConfig::CARDINALITY_UNLIMITED,
    ]);
    $storage->save();
    foreach ($bundles as $bundle) {
      FieldConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'node',
        'bundle' => $bundle,
        'label' => 'Datum',
        'field_storage' => $storage,
      ])->save();
    }
  }

  /**
   * Creates a Smart Date rule.
   *
   * @param string $bundle
   *   The node bundle of the rule.
   * @param string $field_name
   *   The field the rule belongs to.
   *
   * @return \Drupal\smart_date_recur\Entity\SmartDateRule
   *   The rule.
   */
  private function createRule(string $bundle, string $field_name): SmartDateRule {
    $rule = SmartDateRule::create([
      'freq' => 'DAILY',
      'entity_type' => 'node',
      'bundle' => $bundle,
      'field_name' => $field_name,
      'start' => 1767603600,
      'end' => 1767607200,
    ]);
    $rule->save();
    return $rule;
  }

  /**
   * Creates an override of a rule.
   *
   * @param int $rule_id
   *   The rule ID.
   *
   * @return \Drupal\smart_date_recur\Entity\SmartDateOverride
   *   The override.
   */
  private function createOverride(int $rule_id): SmartDateOverride {
    $override = SmartDateOverride::create([
      'rrule' => $rule_id,
      'rrule_index' => 1,
      'value' => 1767690000,
      'end_value' => 1767693600,
    ]);
    $override->save();
    return $override;
  }

}
