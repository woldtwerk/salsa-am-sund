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
 * Tests that update 10010 removes Smart Date content and the modules.
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

    $field_storage = FieldStorageConfig::create([
      'field_name' => 'field_when',
      'entity_type' => 'node',
      'type' => 'smartdate',
      'cardinality' => 1,
    ]);
    $field_storage->save();
    FieldConfig::create([
      'field_name' => 'field_when',
      'entity_type' => 'node',
      'bundle' => 'event',
      'label' => 'Wann',
      'field_storage' => $field_storage,
    ])->save();
  }

  /**
   * Tests the update hook deletes the Smart Date entities and uninstalls.
   */
  public function testUpdateRemovesSmartDate(): void {
    $rule = SmartDateRule::create([
      'freq' => 'DAILY',
      'entity_type' => 'node',
      'bundle' => 'event',
      'field_name' => 'field_when',
      'start' => 1767603600,
      'end' => 1767607200,
    ]);
    $rule->save();
    SmartDateOverride::create([
      'rrule' => $rule->id(),
      'rrule_index' => 1,
      'value' => 1767690000,
      'end_value' => 1767693600,
    ])->save();

    require_once dirname(__DIR__, 3) . '/workspace_events.install';
    workspace_events_update_10010();

    self::assertFalse(\Drupal::moduleHandler()->moduleExists('smart_date_recur'));
    self::assertFalse(\Drupal::moduleHandler()->moduleExists('smart_date'));
    self::assertNull(FieldStorageConfig::loadByName('node', 'field_when'));
    self::assertNull(FieldConfig::loadByName('node', 'event', 'field_when'));
  }

}
