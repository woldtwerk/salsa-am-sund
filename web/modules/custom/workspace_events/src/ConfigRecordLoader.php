<?php

declare(strict_types=1);

namespace Drupal\workspace_events;

use Drupal\Core\Config\FileStorage;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Extension\ExtensionPathResolver;

/**
 * Loads configuration records from the sync or install storage.
 *
 * Update hooks create configuration entities from these records, so the UUIDs
 * match the exported configuration. A later configuration import then updates
 * the existing entities instead of deleting and recreating them.
 */
final class ConfigRecordLoader {

  /**
   * The install storage, initialized on first use.
   */
  private ?FileStorage $installStorage = NULL;

  /**
   * Constructs a ConfigRecordLoader.
   *
   * @param \Drupal\Core\Config\StorageInterface $syncStorage
   *   The configuration sync storage.
   * @param \Drupal\Core\Extension\ExtensionPathResolver $extensionPathResolver
   *   The extension path resolver, used for the module default configuration.
   */
  public function __construct(
    private readonly StorageInterface $syncStorage,
    private readonly ExtensionPathResolver $extensionPathResolver,
  ) {}

  /**
   * Loads a configuration record from the sync or install storage.
   *
   * @param string $name
   *   The configuration name.
   *
   * @return array<mixed>
   *   The configuration data.
   */
  public function load(string $name): array {
    $data = $this->syncStorage->read($name);
    if (is_array($data)) {
      return $data;
    }

    $data = $this->getInstallStorage()->read($name);
    if (!is_array($data)) {
      throw new \RuntimeException(sprintf('Missing configuration %s for workspace_events.', $name));
    }
    return $data;
  }

  /**
   * Returns the install storage of the module.
   *
   * @return \Drupal\Core\Config\FileStorage
   *   The install storage.
   */
  private function getInstallStorage(): FileStorage {
    if ($this->installStorage === NULL) {
      $module_path = $this->extensionPathResolver->getPath('module', 'workspace_events');
      $this->installStorage = new FileStorage($module_path . '/config/install');
    }
    return $this->installStorage;
  }

}
