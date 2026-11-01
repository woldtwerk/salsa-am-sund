<?php

declare(strict_types=1);

namespace Drupal\workspace_events\Plugin\Field\FieldType;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Field\Attribute\FieldType;
use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;

/**
 * Defines a price per lesson with a category and an amount.
 *
 * A single field item holds one category and its price. A field with several
 * items therefore stores the price per category, for example "Normal" for
 * 12,00 € and "Student" for 10,00 €. The available categories are field
 * storage settings, so new categories need no code change.
 */
#[FieldType(
  id: 'workspace_events_price',
  label: new TranslatableMarkup('Price per lesson'),
  description: [
    new TranslatableMarkup('A category and an amount per lesson.'),
  ],
  category: 'number',
  default_widget: 'workspace_events_price_default',
  default_formatter: 'workspace_events_price_default',
)]
final class PriceItem extends FieldItemBase {

  /**
   * {@inheritdoc}
   *
   * The categories are the English source labels. Their German translations
   * live in the module's de.po file.
   *
   * @return array<string, mixed>
   *   The default storage settings.
   */
  public static function defaultStorageSettings(): array {
    /** @var array<string, mixed> $settings */
    $settings = parent::defaultStorageSettings();
    $settings['categories'] = [
      'normal' => 'Normal',
      'student' => 'Student',
    ];
    return $settings;
  }

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition): array {
    $categories = $field_definition->getSetting('categories');
    $properties['category'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Category'))
      ->setRequired(TRUE)
      ->addConstraint('AllowedValues', [
        'choices' => array_keys(is_array($categories) ? $categories : []),
      ]);

    $properties['amount'] = DataDefinition::create('decimal')
      ->setLabel(new TranslatableMarkup('Amount'))
      ->addConstraint('Range', ['min' => 0]);

    return $properties;
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The field schema.
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition): array {
    return [
      'columns' => [
        'category' => [
          'type' => 'varchar',
          'length' => 64,
          'not null' => TRUE,
        ],
        'amount' => [
          'type' => 'numeric',
          'precision' => 10,
          'scale' => 2,
          'not null' => TRUE,
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   *
   * An item is empty when it has no amount. The category alone is only the
   * default selection of an empty widget row and must not create a price.
   */
  public function isEmpty(): bool {
    $amount = $this->get('amount')->getValue();
    return $amount === NULL || $amount === '';
  }

  /**
   * {@inheritdoc}
   *
   * A key that is still used by saved prices cannot be removed safely. This
   * form does not check that: the check would need a database query over the
   * field data table. Removing a used key leaves the saved price with an
   * unknown category, which the AllowedValues constraint then rejects on the
   * next save, so the editor has to pick a valid category again.
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param bool $has_data
   *   Whether the field already has data.
   *
   * @return array<string, mixed>
   *   The storage settings form element.
   */
  public function storageSettingsForm(array &$form, FormStateInterface $form_state, $has_data): array {
    $element = [];

    $categories = $this->getSetting('categories');
    if (!is_array($categories)) {
      $settings = static::defaultStorageSettings();
      $categories = $settings['categories'] ?? [];
    }
    if (!is_array($categories)) {
      $categories = [];
    }

    $lines = [];
    foreach ($categories as $key => $label) {
      if (!is_scalar($label)) {
        continue;
      }
      $lines[] = (string) $key . '|' . (string) $label;
    }

    $element['categories'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Categories'),
      '#default_value' => implode("\n", $lines),
      '#description' => $this->t('One category per line, in the format "key|Label". Keys may contain lowercase letters, numbers and underscores. Renaming a label keeps existing prices. A key that is still used cannot be removed automatically; check the saved prices first.'),
      '#rows' => max(5, count($categories) + 1),
      '#required' => TRUE,
      '#element_validate' => [[static::class, 'validateCategories']],
    ];

    return $element;
  }

  /**
   * Validates the "key|Label" lines and stores them as a keyed array.
   *
   * @param array<string, mixed> $element
   *   The categories textarea.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param array<string, mixed> $complete_form
   *   The complete form.
   */
  public static function validateCategories(array &$element, FormStateInterface $form_state, array &$complete_form): void {
    $categories = [];
    $value = $element['#value'] ?? '';
    $lines = preg_split('/\R/', is_scalar($value) ? (string) $value : '') ?: [];

    foreach ($lines as $index => $line) {
      $line = trim($line);
      if ($line === '') {
        continue;
      }

      $line_number = $index + 1;
      if (!str_contains($line, '|')) {
        self::setError($element, $form_state, new TranslatableMarkup('Line @line: use the format "key|Label".', ['@line' => $line_number]));
        return;
      }

      [$key, $label] = array_map('trim', explode('|', $line, 2));

      if (!preg_match('/^[a-z0-9_]+$/', $key)) {
        self::setError($element, $form_state, new TranslatableMarkup('Line @line: the key "@key" may only contain lowercase letters, numbers and underscores.', [
          '@line' => $line_number,
          '@key' => $key,
        ]));
        return;
      }
      if (mb_strlen($key) > 64) {
        self::setError($element, $form_state, new TranslatableMarkup('Line @line: the key "@key" is longer than 64 characters.', [
          '@line' => $line_number,
          '@key' => $key,
        ]));
        return;
      }
      if (isset($categories[$key])) {
        self::setError($element, $form_state, new TranslatableMarkup('Line @line: the key "@key" is used twice.', [
          '@line' => $line_number,
          '@key' => $key,
        ]));
        return;
      }
      if ($label === '') {
        self::setError($element, $form_state, new TranslatableMarkup('Line @line: the label must not be empty.', ['@line' => $line_number]));
        return;
      }

      $categories[$key] = $label;
    }

    if ($categories === []) {
      self::setError($element, $form_state, new TranslatableMarkup('At least one category is required.'));
      return;
    }

    $form_state->setValueForElement($element, $categories);
  }

  /**
   * Attaches an error to a form element by its name.
   *
   * The form state sets errors by element name. Using the name keeps the
   * by-reference element type of the validation callback out of the call.
   *
   * @param array<string, mixed> $element
   *   The form element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $message
   *   The error message.
   */
  private static function setError(array $element, FormStateInterface $form_state, TranslatableMarkup $message): void {
    $name = $element['#name'] ?? NULL;
    $form_state->setErrorByName(is_string($name) ? $name : '', $message);
  }

  /**
   * Returns the configured label of this item's category.
   *
   * @return string
   *   The category label. Falls back to the stored key when the category was
   *   removed from the storage settings.
   */
  public function getCategoryLabel(): string {
    $category = (string) $this->get('category')->getString();
    if ($category === '') {
      return '';
    }

    $categories = $this->getSetting('categories');
    $label = is_array($categories) && isset($categories[$category]) ? $categories[$category] : $category;

    if ($label instanceof MarkupInterface) {
      return (string) $label;
    }
    return is_scalar($label) && (string) $label !== '' ? (string) $label : $category;
  }

  /**
   * Formats an amount with a comma as decimal separator and a euro suffix.
   *
   * @param mixed $amount
   *   The stored amount.
   *
   * @return string
   *   The formatted amount, for example "12,00 €".
   */
  public static function formatAmount(mixed $amount): string {
    $value = is_numeric($amount) ? (float) $amount : 0.0;
    return number_format($value, 2, ',', '.') . ' €';
  }

}
