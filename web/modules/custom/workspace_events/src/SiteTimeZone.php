<?php

declare(strict_types=1);

namespace Drupal\workspace_events;

use Drupal\Component\Datetime\TimeInterface;
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
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TimeInterface $time,
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
   * Returns the current time in the site timezone.
   *
   * @return \DateTimeImmutable
   *   The current time.
   */
  public function now(): \DateTimeImmutable {
    return (new \DateTimeImmutable('@' . $this->time->getCurrentTime()))
      ->setTimezone($this->get());
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

  /**
   * Converts a Unix timestamp to a date in the site timezone.
   *
   * @param int $timestamp
   *   The timestamp.
   *
   * @return \DateTimeImmutable
   *   The date in the site timezone.
   */
  public function fromTimestamp(int $timestamp): \DateTimeImmutable {
    return (new \DateTimeImmutable('@' . $timestamp))->setTimezone($this->get());
  }

}
