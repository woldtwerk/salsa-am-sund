<?php

declare(strict_types=1);

namespace Drupal\workspace_events\Plugin\Field\FieldFormatter;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Field\Attribute\FieldFormatter;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\workspace_events\Plugin\Field\FieldType\PriceItem;

/**
 * Formats each price as "Normal: 12,00 € per lesson".
 *
 * @extends \Drupal\Core\Field\FormatterBase<\Drupal\Core\Field\FieldItemListInterface<\Drupal\workspace_events\Plugin\Field\FieldType\PriceItem>>
 */
#[FieldFormatter(
  id: 'workspace_events_price_default',
  label: new TranslatableMarkup('Price per lesson'),
  field_types: ['workspace_events_price'],
)]
final class PriceFormatter extends FormatterBase {

  /**
   * {@inheritdoc}
   *
   * @param \Drupal\Core\Field\FieldItemListInterface<\Drupal\workspace_events\Plugin\Field\FieldType\PriceItem> $items
   *   The price items.
   * @param string|null $langcode
   *   The language code.
   *
   * @return array<int, array<int|string, mixed>>
   *   The formatted prices.
   */
  public function viewElements(FieldItemListInterface $items, $langcode): array {
    /** @var array<int, array<int|string, mixed>> $elements */
    $elements = [];

    $cacheability = new CacheableMetadata();
    $cacheability->addCacheableDependency($items->getFieldDefinition()->getFieldStorageDefinition());
    $cacheability->addCacheContexts(['languages:language_interface']);

    foreach ($items as $delta => $item) {
      if (!$item instanceof PriceItem || $item->isEmpty()) {
        continue;
      }

      $elements[$delta] = [
        '#markup' => $this->t('@category: @amount per lesson', [
          '@category' => $item->getCategoryLabel(),
          '@amount' => PriceItem::formatAmount($item->get('amount')->getString()),
        ]),
      ];
    }

    $cacheability->applyTo($elements);

    /** @var array<int, array<int|string, mixed>> $elements */
    return $elements;
  }

}
