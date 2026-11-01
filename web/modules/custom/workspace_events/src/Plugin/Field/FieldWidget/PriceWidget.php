<?php

declare(strict_types=1);

namespace Drupal\workspace_events\Plugin\Field\FieldWidget;

use Drupal\Core\Field\Attribute\FieldWidget;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Widget for a price per lesson: category select and amount input.
 */
#[FieldWidget(
  id: 'workspace_events_price_default',
  label: new TranslatableMarkup('Price per lesson'),
  field_types: ['workspace_events_price'],
)]
final class PriceWidget extends WidgetBase {

  /**
   * {@inheritdoc}
   *
   * @param \Drupal\Core\Field\FieldItemListInterface<\Drupal\workspace_events\Plugin\Field\FieldType\PriceItem> $items
   *   The price items.
   * @param int $delta
   *   The delta of the item.
   * @param array<string, mixed> $element
   *   The form element.
   * @param array<string, mixed> $form
   *   The complete form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array<string, mixed>
   *   The form element.
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state): array {
    $categories = $items->getFieldDefinition()->getSetting('categories');
    $categories = is_array($categories) ? $categories : [];

    $options = [];
    foreach ($categories as $key => $label) {
      if (is_scalar($label)) {
        $options[$key] = (string) $label;
      }
    }

    $element['category'] = [
      '#type' => 'select',
      '#title' => $this->t('Category'),
      '#options' => $options,
      '#empty_option' => $this->t('- Select -'),
      '#empty_value' => '',
      '#default_value' => $items[$delta]->category ?? NULL,
    ];

    $element['amount'] = [
      '#type' => 'number',
      '#title' => $this->t('Amount'),
      '#default_value' => $items[$delta]->amount ?? NULL,
      '#min' => 0,
      '#step' => 0.01,
      '#field_suffix' => '€',
    ];

    return $element;
  }

}
