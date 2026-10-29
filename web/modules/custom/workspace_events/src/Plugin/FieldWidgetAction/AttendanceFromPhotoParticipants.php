<?php

declare(strict_types=1);

namespace Drupal\workspace_events\Plugin\FieldWidgetAction;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Entity\Display\EntityFormDisplayInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\field_widget_actions\Attribute\FieldWidgetAction;
use Drupal\field_widget_actions\FieldWidgetActionBase;
use Drupal\user\UserInterface;
use Drupal\workspace_events\Attendance\AttendanceRecognitionException;
use Drupal\workspace_events\Attendance\AttendanceRecognizer;
use Drupal\workspace_events\Attendance\MemberMatcher;
use Drupal\workspace_events\Attendance\MemberNameLoaderInterface;
use Drupal\workspace_events\AttendanceRecognition;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Adds the attendance recognition button to the participant field.
 *
 * The photo only lives in the PHP upload of the request: it is read here,
 * sent to the OCR service and never written to the file system, so no file
 * entity is created and no image stays on the site.
 */
#[FieldWidgetAction(
  id: 'attendance_from_photo_participants',
  label: new TranslatableMarkup('Teilnehmer aus Foto erkennen'),
  widget_types: [
    'entity_reference_autocomplete',
    'entity_reference_autocomplete_tags',
  ],
  field_types: ['entity_reference'],
  category: new TranslatableMarkup('Anwesenheit'),
)]
final class AttendanceFromPhotoParticipants extends FieldWidgetActionBase {

  /**
   * The property of the autocomplete element that holds the reference.
   */
  public const string FORM_ELEMENT_PROPERTY = 'target_id';

  /**
   * The name of the upload form element and of its entry in the files bag.
   */
  private const string UPLOAD_ELEMENT = 'attendance_photo_upload';

  /**
   * The form element holding the selects for ambiguous recognized names.
   */
  private const string AMBIGUOUS_ELEMENT = 'attendance_ambiguous';

  /**
   * The key of the "apply selection" button inside the ambiguous element.
   */
  private const string APPLY_BUTTON = 'apply';

  /**
   * The form state key prefix of the ambiguous candidates per field.
   */
  private const string AMBIGUOUS_STATE_PREFIX = 'workspace_events.attendance_ambiguous.';

  /**
   * The largest accepted upload in bytes.
   */
  private const int MAX_FILE_SIZE = 10 * 1024 * 1024;

  /**
   * The accepted MIME types.
   *
   * @var string[]
   */
  private const array ALLOWED_MIME_TYPES = [
    'image/jpeg',
    'image/png',
    'image/webp',
  ];

  /**
   * The accepted file extensions.
   *
   * @var string[]
   */
  private const array ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

  /**
   * The messenger.
   */
  protected readonly MessengerInterface $messengerService;

  /**
   * Constructs an AttendanceFromPhotoParticipants action.
   *
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user.
   * @param \Drupal\workspace_events\Attendance\AttendanceRecognizer $recognizer
   *   The attendance photo recognizer.
   * @param \Drupal\workspace_events\Attendance\MemberMatcher $memberMatcher
   *   The member matcher.
   * @param \Drupal\workspace_events\Attendance\MemberNameLoaderInterface $memberNameLoader
   *   The member name loader.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    MessengerInterface $messenger,
    protected readonly AccountProxyInterface $currentUser,
    protected readonly AttendanceRecognizer $recognizer,
    protected readonly MemberMatcher $memberMatcher,
    protected readonly MemberNameLoaderInterface $memberNameLoader,
    protected readonly RequestStack $requestStack,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $messenger);
    $this->messengerService = $messenger;
  }

  /**
   * {@inheritdoc}
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container.
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('messenger'),
      $container->get('current_user'),
      $container->get(AttendanceRecognizer::class),
      $container->get(MemberMatcher::class),
      $container->get(MemberNameLoaderInterface::class),
      $container->get('request_stack'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function isAvailable(): bool {
    return $this->currentUser->hasPermission(AttendanceRecognition::PERMISSION);
  }

  /**
   * {@inheritdoc}
   *
   * The recognition fills the whole field from one photo, so the button and
   * the upload are shown once per field instead of once per delta.
   */
  public function getMultiple(): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function getAjaxCallback(): string {
    return 'recognizeAjax';
  }

  /**
   * {@inheritdoc}
   *
   * @param array<mixed> $form
   *   The field widget form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param array<string, mixed> $context
   *   The widget form element context.
   */
  protected function actionButton(array &$form, FormStateInterface $form_state, array $context = []): void {
    parent::actionButton($form, $form_state, $context);

    $items = $context['items'] ?? NULL;
    if (!$items instanceof FieldItemListInterface) {
      return;
    }
    $fieldName = $items->getFieldDefinition()->getName();
    $widgetId = $this->getActionButtonWidgetId($fieldName, $context);

    $button = $form[$widgetId] ?? NULL;
    if (!is_array($button)) {
      return;
    }
    $submit = $button['#submit'] ?? [];
    if (!is_array($submit)) {
      $submit = [];
    }
    $submit[] = [$this, 'recognizeSubmit'];
    $button['#submit'] = $submit;
    $button['#executes_submit_callback'] = TRUE;
    $form[$widgetId] = $button;

    $weight = $this->configuration['weight'] ?? 0;
    $baseWeight = is_numeric($weight) ? (int) $weight : 0;
    $form[self::UPLOAD_ELEMENT] = [
      '#type' => 'file',
      '#title' => $this->t('Anwesenheitsfoto'),
      '#description' => $this->t('JPEG, PNG oder WebP, höchstens 10 MB. Das Foto wird nur zur Erkennung übertragen und nicht gespeichert.'),
      '#weight' => $baseWeight + 9,
      '#parents' => [self::UPLOAD_ELEMENT],
      '#attributes' => [
        'accept' => 'image/jpeg,image/png,image/webp',
      ],
    ];

    $this->ambiguousSelections($form, $form_state, $fieldName, $baseWeight + 11);
  }

  /**
   * Renders a select per ambiguous recognized name below the action button.
   *
   * The candidates live only in the form state, so nothing is persisted until
   * the editor applies a selection.
   *
   * @param array<mixed> $form
   *   The field widget form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param string $fieldName
   *   The participant field name.
   * @param int $weight
   *   The weight of the selection form.
   */
  private function ambiguousSelections(array &$form, FormStateInterface $form_state, string $fieldName, int $weight): void {
    $ambiguous = $this->ambiguousCandidates($form_state, $fieldName);
    if ($ambiguous === []) {
      return;
    }
    $names = $this->memberNameLoader->load();

    $element = [
      '#type' => 'container',
      '#tree' => TRUE,
      '#weight' => $weight,
      '#attributes' => [
        'class' => ['attendance-ambiguous'],
      ],
    ];
    $index = 0;
    foreach ($ambiguous as $label => $ids) {
      $options = [0 => $this->t('– niemand –')];
      foreach ($ids as $id) {
        $options[$id] = $names[$id] ?? (string) $id;
      }
      $element['select_' . $index] = [
        '#type' => 'select',
        '#title' => $this->t('Wer ist „@name“?', ['@name' => $label]),
        '#options' => $options,
        '#default_value' => 0,
      ];
      $index++;
    }
    $element[self::APPLY_BUTTON] = [
      '#type' => 'button',
      '#value' => $this->t('Auswahl übernehmen'),
      '#name' => $fieldName . '_attendance_apply',
      '#field_widget_action_field_name' => $fieldName,
      '#field_widget_action_field_delta' => NULL,
      '#executes_submit_callback' => TRUE,
      '#submit' => [[$this, 'applySelectionSubmit']],
      '#validate' => ['::validateForm', [$this, 'clearErrorsForAction']],
      '#ajax' => [
        'callback' => [$this, 'reportResultAjax'],
        'wrapper' => 'field-widget-action-' . $fieldName,
        'prevent' => 'submit',
      ],
    ];

    $form[self::AMBIGUOUS_ELEMENT] = $element;
  }

  /**
   * Ajax callback returning the rebuilt participant widget.
   *
   * @param array<mixed> $form
   *   The rebuilt form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array<mixed>
   *   The participant field subtree.
   */
  public function recognizeAjax(array &$form, FormStateInterface $form_state): array {
    $fieldName = $this->getTargetElementFieldName($form, $form_state);
    if (!is_string($fieldName) || $fieldName === '') {
      return [];
    }
    $element = $form[$fieldName] ?? [];
    return is_array($element) ? $element : [];
  }

  /**
   * Reads the uploaded photo, recognizes the names and fills the field.
   *
   * @param array<mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function recognizeSubmit(array &$form, FormStateInterface $form_state): void {
    $triggeringElement = $form_state->getTriggeringElement();
    $fieldName = $triggeringElement['#field_widget_action_field_name'] ?? NULL;
    if (!is_string($fieldName) || $fieldName === '' || !isset($form[$fieldName])) {
      return;
    }

    $photo = $this->uploadedPhoto();
    if ($photo === NULL) {
      $this->messengerService->addError($this->t('Bitte ein Anwesenheitsfoto auswählen.'));
      return;
    }

    if (!$photo->isValid()) {
      $this->messengerService->addError($this->t('Das Foto konnte nicht hochgeladen werden.'));
      return;
    }
    $size = $photo->getSize();
    if ($size === FALSE) {
      $this->messengerService->addError($this->t('Das Anwesenheitsfoto konnte nicht gelesen werden.'));
      return;
    }
    if ($size > self::MAX_FILE_SIZE) {
      $this->messengerService->addError($this->t('Das Foto darf höchstens 10 MB groß sein.'));
      return;
    }

    $image = @getimagesize($photo->getPathname());
    $mimeType = is_array($image) ? $image['mime'] : NULL;
    $extension = mb_strtolower($photo->getClientOriginalExtension());
    if (!is_string($mimeType) || !in_array($mimeType, self::ALLOWED_MIME_TYPES, TRUE) || !in_array($extension, self::ALLOWED_EXTENSIONS, TRUE)) {
      $this->messengerService->addError($this->t('Bitte ein Foto im Format JPEG, PNG oder WebP hochladen.'));
      return;
    }

    try {
      $contents = $photo->getContent();
    }
    catch (\Throwable) {
      $this->messengerService->addError($this->t('Das Anwesenheitsfoto konnte nicht gelesen werden.'));
      return;
    }

    try {
      $names = $this->recognizer->recognizeImage($contents, $mimeType);
    }
    catch (AttendanceRecognitionException $exception) {
      $this->messengerService->addError($exception->getMessage());
      return;
    }

    if ($names === []) {
      $this->messengerService->addWarning($this->t('Auf dem Anwesenheitsfoto wurden keine Namen erkannt.'));
      $this->reportNoValueProduced($form_state, $fieldName);
      return;
    }

    $result = $this->memberMatcher->match($names);
    // Replace the candidates of a previous run; an empty list also removes the
    // selects that were rendered for them.
    $form_state->set($this->ambiguousKey($fieldName), $result['ambiguous']);
    if ($result['unmatched'] !== []) {
      $this->messengerService->addWarning($this->t('Kein Mitglied gefunden: @names', [
        '@names' => implode(', ', $result['unmatched']),
      ]));
    }
    if ($result['ambiguous'] !== []) {
      $this->messengerService->addStatus($this->t('Bitte auswählen: @names', [
        '@names' => implode(', ', array_keys($result['ambiguous'])),
      ]));
    }

    if ($result['matched'] !== []) {
      $this->addParticipants($form, $form_state, $fieldName, $result['matched']);
      return;
    }
    if ($result['ambiguous'] === []) {
      $this->reportNoValueProduced($form_state, $fieldName);
      return;
    }

    // Only ambiguous names were found: rebuild so the selects are rendered.
    $form_state->setRebuild();
  }

  /**
   * Adds the selected members of the ambiguous names to the participant field.
   *
   * @param array<mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function applySelectionSubmit(array &$form, FormStateInterface $form_state): void {
    if (!$this->isAvailable()) {
      return;
    }

    $triggeringElement = $form_state->getTriggeringElement();
    $fieldName = $triggeringElement['#field_widget_action_field_name'] ?? NULL;
    if (!is_string($fieldName) || $fieldName === '') {
      return;
    }

    $ambiguous = $this->ambiguousCandidates($form_state, $fieldName);
    if ($ambiguous === []) {
      return;
    }

    $parents = $triggeringElement['#parents'] ?? [];
    if (!is_array($parents)) {
      $parents = [];
    }
    // Drop the button from the parents to reach the container holding the
    // selects.
    array_pop($parents);
    $values = $form_state->getValue($parents);
    if (!is_array($values)) {
      $values = [];
    }

    $selected = [];
    $index = 0;
    foreach ($ambiguous as $ids) {
      $id = $values['select_' . $index] ?? 0;
      if (is_numeric($id) && in_array((int) $id, $ids, TRUE)) {
        $selected[(int) $id] = (int) $id;
      }
      $index++;
    }

    // The editor has answered the prompts, so they are no longer shown.
    $form_state->set($this->ambiguousKey($fieldName), []);

    if ($selected !== []) {
      $this->addParticipants($form, $form_state, $fieldName, $selected);
      return;
    }

    $form_state->setRebuild();
  }

  /**
   * Adds participant IDs to the field and keeps the widget input in sync.
   *
   * @param array<mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param string $fieldName
   *   The participant field name.
   * @param int[] $selected
   *   The user IDs to add.
   */
  private function addParticipants(array &$form, FormStateInterface $form_state, string $fieldName, array $selected): void {
    $entity = $this->buildEntity($form, $form_state);
    if (!$entity instanceof FieldableEntityInterface || !$entity->hasField($fieldName)) {
      $this->messengerService->addError($this->t('Die Teilnehmer konnten nicht übernommen werden.'));
      return;
    }

    $ids = $this->participantIds($entity, $fieldName);
    foreach ($selected as $id) {
      $ids[$id] = $id;
    }
    unset($ids[0]);

    $entity->set($fieldName, array_values($ids));
    $this->setFormInput($entity, $form_state, $fieldName);
    $this->updateItemsCount($form, $form_state, $fieldName, count($ids));
    $this->reportProducedValue($form_state, $fieldName);
    $form_state->setRebuild();
  }

  /**
   * Returns the unresolved ambiguous names of a field from the form state.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param string $fieldName
   *   The participant field name.
   *
   * @return array<string, int[]>
   *   The candidate user IDs per recognized name.
   */
  private function ambiguousCandidates(FormStateInterface $form_state, string $fieldName): array {
    $stored = $form_state->get($this->ambiguousKey($fieldName));
    if (!is_array($stored)) {
      return [];
    }

    $ambiguous = [];
    foreach ($stored as $label => $ids) {
      if (!is_string($label) || !is_array($ids)) {
        continue;
      }
      $candidates = [];
      foreach ($ids as $id) {
        if (is_int($id) || (is_string($id) && ctype_digit($id))) {
          $candidates[] = (int) $id;
        }
      }
      if ($candidates !== []) {
        $ambiguous[$label] = $candidates;
      }
    }
    return $ambiguous;
  }

  /**
   * Returns the form state key that holds the ambiguous candidates.
   *
   * @param string $fieldName
   *   The participant field name.
   *
   * @return string
   *   The form state key.
   */
  private function ambiguousKey(string $fieldName): string {
    return self::AMBIGUOUS_STATE_PREFIX . $fieldName;
  }

  /**
   * Returns the uploaded photo of the current request.
   *
   * @return \Symfony\Component\HttpFoundation\File\UploadedFile|null
   *   The uploaded photo, or NULL when there is none.
   */
  private function uploadedPhoto(): ?UploadedFile {
    $request = $this->requestStack->getCurrentRequest();
    if ($request === NULL) {
      return NULL;
    }
    $files = $request->files->get('files');
    if (!is_array($files)) {
      return NULL;
    }
    $photo = $files[self::UPLOAD_ELEMENT] ?? NULL;
    return $photo instanceof UploadedFile ? $photo : NULL;
  }

  /**
   * Returns the participant IDs that the form currently holds.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity built from the submitted form values.
   * @param string $fieldName
   *   The participant field name.
   *
   * @return array<int, int>
   *   The participant IDs, keyed by ID.
   */
  private function participantIds(FieldableEntityInterface $entity, string $fieldName): array {
    $ids = [];
    foreach ($entity->get($fieldName) as $item) {
      $id = $item->get('target_id')->getString();
      if ($id !== '' && ctype_digit($id) && (int) $id > 0) {
        $ids[(int) $id] = (int) $id;
      }
    }
    return $ids;
  }

  /**
   * Writes the participants into the user input of the widget.
   *
   * The tags widget renders one text input for all values and expects the
   * comma separated "Name (ID)" format instead of one element per delta.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity that holds the participants.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param string $fieldName
   *   The participant field name.
   */
  private function setFormInput(FieldableEntityInterface $entity, FormStateInterface $form_state, string $fieldName): void {
    $input = $form_state->getUserInput();

    if ($this->widgetHandlesMultipleValues($form_state, $fieldName)) {
      $tags = [];
      foreach ($entity->get($fieldName) as $item) {
        $user = $item->get('entity')->getValue();
        if (!$user instanceof UserInterface) {
          continue;
        }
        $tags[] = $user->getDisplayName() . ' (' . $user->id() . ')';
      }

      $fieldInput = $input[$fieldName] ?? [];
      if (!is_array($fieldInput)) {
        $fieldInput = [];
      }
      $fieldInput[self::FORM_ELEMENT_PROPERTY] = implode(', ', $tags);
      $input[$fieldName] = $fieldInput;
    }
    else {
      $input[$fieldName] = $entity->get($fieldName)->getValue();
    }

    $form_state->setUserInput($input);
  }

  /**
   * Bumps the rebuilt widget's item count for multi-value autocomplete fields.
   *
   * Multi-value widgets render one sub-element per delta based on the field
   * state. Without this, a rebuilt widget keeps the delta slots it had before
   * the recognition and the new participants would not be editable.
   *
   * @param array<mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param string $fieldName
   *   The participant field name.
   * @param int $count
   *   The number of participants.
   */
  private function updateItemsCount(array $form, FormStateInterface $form_state, string $fieldName, int $count): void {
    if ($this->widgetHandlesMultipleValues($form_state, $fieldName)) {
      return;
    }

    $field = $form[$fieldName] ?? NULL;
    $widget = is_array($field) ? ($field['widget'] ?? NULL) : NULL;
    $parents = is_array($widget) ? ($widget['#field_parents'] ?? []) : [];
    if (!is_array($parents)) {
      return;
    }

    $fieldState = WidgetBase::getWidgetState($parents, $fieldName, $form_state);
    $required = max(0, $count - 1);
    if ($required > ($fieldState['items_count'] ?? 0)) {
      $fieldState['items_count'] = $required;
      WidgetBase::setWidgetState($parents, $fieldName, $form_state, $fieldState);
    }
  }

  /**
   * Returns whether the widget renders all values as one element.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state carrying the form display.
   * @param string $fieldName
   *   The participant field name.
   *
   * @return bool
   *   TRUE when the widget declares multiple_values.
   */
  private function widgetHandlesMultipleValues(FormStateInterface $form_state, string $fieldName): bool {
    $formDisplay = NestedArray::getValue($form_state->getStorage(), ['form_display']);
    if (!$formDisplay instanceof EntityFormDisplayInterface) {
      return FALSE;
    }
    $widget = $formDisplay->getRenderer($fieldName);
    if ($widget === NULL) {
      return FALSE;
    }
    $definition = $widget->getPluginDefinition();
    return is_array($definition) && !empty($definition['multiple_values']);
  }

}
