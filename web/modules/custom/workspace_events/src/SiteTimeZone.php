<?php

declare(strict_types=1);

namespace Drupal\workspace_events;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface;

/**
 * Returns the timezone of the site.
 */
final class SiteTimeZone {

  /**
   * Constructs a SiteTimeZone.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Returns the timezone configured for the site.
   *
   * @return \DateTimeZone
   *   The site timezone, falling back to the PHP timezone when the
   *   configuration is missing or invalid.
   */
  public function get(): \DateTimeZone {
    $timezone = (string) $this->configFactory->get('system.date')->get('timezone.default');
    if ($timezone === '') {
      $timezone = date_default_timezone_get();
    }
    try {
      return new \DateTimeZone($timezone);
    }
    catch (\Exception) {
      return new \DateTimeZone(date_default_timezone_get());
    }
  }

  /**
   * Converts a stored date value to a date in the site timezone.
   *
   * Date and time fields store their values in UTC.
   *
   * @param string $value
   *   The stored value.
   *
   * @return \DateTimeImmutable
   *   The date in the site timezone.
   */
  public function fromStorageValue(string $value): \DateTimeImmutable {
    return (new \DateTimeImmutable($value, new \DateTimeZone(DateTimeItemInterface::STORAGE_TIMEZONE)))
      ->setTimezone($this->get());
  }

}
