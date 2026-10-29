<?php

declare(strict_types=1);

namespace Drupal\workspace_events\Plugin\FieldWidgetAction;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Entity\ContentEntityFormInterface;
use Drupal\Core\Entity\Display\EntityFormDisplayInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\field_widget_actions\Attribute\FieldWidgetAction;
use Drupal\field_widget_actions\FieldWidgetActionBase;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;
use Drupal\workspace_events\Attendance\AttendanceRecognitionException;
use Drupal\workspace_events\Attendance\AttendanceRecognizer;
use Drupal\workspace_events\Attendance\MemberMatcher;
use Drupal\workspace_events\Attendance\MemberNameLoaderInterface;
use Drupal\workspace_events\Attendance\PreviousAttendance;
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
  label: new TranslatableMarkup('Recognize participants from a photo'),
  widget_types: [
    'entity_reference_autocomplete',
    'entity_reference_autocomplete_tags',
  ],
  field_types: ['entity_reference'],
  category: new TranslatableMarkup('Attendance'),
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
   * The form element holding the review table of the recognized names.
   */
  private const string REVIEW_ELEMENT = 'attendance_review';

  /**
   * The key of the "apply selection" button inside the review element.
   */
  private const string APPLY_BUTTON = 'apply';

  /**
   * The form state key prefix of the review rows per field.
   */
  private const string REVIEW_STATE_PREFIX = 'workspace_events.attendance_review.';

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
   * @param \Drupal\workspace_events\Attendance\PreviousAttendance $previousAttendance
   *   The recommender by earlier lessons.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
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
    protected readonly PreviousAttendance $previousAttendance,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
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
      $container->get(PreviousAttendance::class),
      $container->get('entity_type.manager'),
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
      '#title' => $this->t('Attendance photo'),
      '#description' => $this->t('JPEG, PNG or WebP, up to 10 MB. The photo is only sent for recognition and is not stored.'),
      '#weight' => $baseWeight + 9,
      '#parents' => [self::UPLOAD_ELEMENT],
      '#attributes' => [
        'accept' => 'image/jpeg,image/png,image/webp',
      ],
    ];

    $this->reviewTable($form, $form_state, $items, $baseWeight + 11);
  }

  /**
   * Renders the review table of the recognized names below the action button.
   *
   * Every row holds the recognized text, an autocomplete for the member and a
   * hint why the member is proposed. The rows live only in the form state, and
   * nothing is added to the field until the editor applies the selection.
   *
   * @param array<mixed> $form
   *   The field widget form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param \Drupal\Core\Field\FieldItemListInterface<\Drupal\Core\Field\FieldItemInterface> $items
   *   The participant field.
   * @param int $weight
   *   The weight of the review table.
   */
  private function reviewTable(array &$form, FormStateInterface $form_state, FieldItemListInterface $items, int $weight): void {
    $fieldName = $items->getFieldDefinition()->getName();
    $rows = $this->reviewRows($form_state, $fieldName);
    if ($rows === []) {
      return;
    }

    $fieldDefinition = $items->getFieldDefinition();
    $handler = $fieldDefinition->getSetting('handler');
    $handlerSettings = $fieldDefinition->getSetting('handler_settings');

    $recommended = [];
    foreach ($rows as $row) {
      foreach ($row['recommended'] as $id) {
        $recommended[$id] = $id;
      }
    }
    $users = $recommended === []
      ? []
      : $this->entityTypeManager->getStorage('user')->loadMultiple($recommended);
    $names = $this->memberNameLoader->load();

    $element = [
      '#type' => 'container',
      '#tree' => TRUE,
      '#weight' => $weight,
      '#attributes' => [
        'class' => ['attendance-review'],
      ],
    ];
    $table = [
      '#type' => 'table',
      '#tree' => TRUE,
      '#header' => [
        $this->t('Recognized'),
        $this->t('Member'),
        $this->t('Hint'),
      ],
      '#attributes' => [
        'class' => ['attendance-review__table'],
      ],
    ];
    foreach ($rows as $index => $row) {
      $table[$index] = [
        'erkannt' => [
          '#plain_text' => $row['name'],
        ],
        'mitglied' => [
          '#type' => 'entity_autocomplete',
          '#target_type' => 'user',
          '#selection_handler' => is_string($handler) ? $handler : 'default:user',
          '#selection_settings' => is_array($handlerSettings) ? $handlerSettings : [],
          '#tags' => TRUE,
          '#title' => $this->t('Members for @name', ['@name' => $row['name']]),
          '#title_display' => 'invisible',
          '#size' => 40,
          '#default_value' => array_values(array_filter(
            array_map(static fn (int $id): mixed => $users[$id] ?? NULL, $row['recommended']),
          )),
        ],
        'hinweis' => [
          '#markup' => $this->reviewHint($row, $names),
        ],
      ];
    }
    $element['table'] = $table;
    $element[self::APPLY_BUTTON] = [
      '#type' => 'button',
      '#value' => $this->t('Apply participants'),
      '#name' => $fieldName . '_attendance_apply',
      '#field_widget_action_field_name' => $fieldName,
      '#field_widget_action_field_delta' => NULL,
      '#executes_submit_callback' => TRUE,
      '#submit' => [[$this, 'applyReviewSubmit']],
      '#validate' => ['::validateForm', [$this, 'clearErrorsForAction']],
      '#ajax' => [
        'callback' => [$this, 'reportResultAjax'],
        'wrapper' => 'field-widget-action-' . $fieldName,
        'prevent' => 'submit',
      ],
    ];

    $form[self::REVIEW_ELEMENT] = $element;
  }

  /**
   * Returns the hint for a review row.
   *
   * @param array{name: string, candidates: int[], recommended: int[], reason: string|null} $row
   *   The review row.
   * @param array<int, string> $names
   *   The member display names keyed by user ID.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The hint.
   */
  private function reviewHint(array $row, array $names): TranslatableMarkup {
    if (count($row['candidates']) === 1 && $row['recommended'] !== []) {
      return $this->t('unambiguous');
    }
    if ($row['reason'] === 'regular') {
      return $this->t('attend regularly');
    }
    if ($row['reason'] === 'previous') {
      return $this->t('same as last lesson');
    }
    if ($row['candidates'] === []) {
      return $this->t('no member found');
    }
    $labels = [];
    foreach ($row['candidates'] as $id) {
      $labels[] = $names[$id] ?? (string) $id;
    }
    return $this->t('ambiguous: @names – please choose', [
      '@names' => implode(', ', $labels),
    ]);
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
   * Reads the uploaded photo, recognizes the names and proposes the members.
   *
   * @param array<mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function recognizeSubmit(array &$form, FormStateInterface $form_state): void {
    if (!$this->isAvailable()) {
      return;
    }

    $triggeringElement = $form_state->getTriggeringElement();
    $fieldName = $triggeringElement['#field_widget_action_field_name'] ?? NULL;
    if (!is_string($fieldName) || $fieldName === '' || !isset($form[$fieldName])) {
      return;
    }

    $photo = $this->uploadedPhoto();
    if ($photo === NULL) {
      $this->messengerService->addError($this->t('Please select an attendance photo.'));
      return;
    }

    if (!$photo->isValid()) {
      $this->messengerService->addError($this->t('The photo could not be uploaded.'));
      return;
    }
    $size = $photo->getSize();
    if ($size === FALSE) {
      $this->messengerService->addError($this->t('The attendance photo could not be read.'));
      return;
    }
    if ($size > self::MAX_FILE_SIZE) {
      $this->messengerService->addError($this->t('The photo must not be larger than 10 MB.'));
      return;
    }

    $image = @getimagesize($photo->getPathname());
    $mimeType = is_array($image) ? $image['mime'] : NULL;
    $extension = mb_strtolower($photo->getClientOriginalExtension());
    if (!is_string($mimeType) || !in_array($mimeType, self::ALLOWED_MIME_TYPES, TRUE) || !in_array($extension, self::ALLOWED_EXTENSIONS, TRUE)) {
      $this->messengerService->addError($this->t('Please upload a photo in JPEG, PNG or WebP format.'));
      return;
    }

    try {
      $contents = $photo->getContent();
    }
    catch (\Throwable) {
      $this->messengerService->addError($this->t('The attendance photo could not be read.'));
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
      $this->messengerService->addWarning($this->t('No names were recognized on the attendance photo.'));
      $this->reportNoValueProduced($form_state, $fieldName);
      return;
    }

    $rows = $this->memberMatcher->match($names);
    if ($rows === []) {
      $this->messengerService->addWarning($this->t('No names were recognized on the attendance photo.'));
      $this->reportNoValueProduced($form_state, $fieldName);
      return;
    }

    $rows = $this->addPreviousRecommendations($rows, $form_state);
    // Replace the rows of a previous run; an empty list also removes the table
    // that was rendered for them.
    $form_state->set($this->reviewKey($fieldName), $rows);
    $this->messengerService->addStatus($this->t('Please review the suggestions and apply them.'));

    // Nothing is added before the editor applies the selection.
    $form_state->setRebuild();
  }

  /**
   * Fills the recommendation of ambiguous rows from the earlier lessons.
   *
   * @param array<int, array{name: string, candidates: int[], recommended: int|null}> $rows
   *   The recognized rows.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array<int, array{name: string, candidates: int[], recommended: int[], reason: string|null}>
   *   The rows of the review table, with the recommended members.
   */
  private function addPreviousRecommendations(array $rows, FormStateInterface $form_state): array {
    $formObject = $form_state->getFormObject();
    $lesson = $formObject instanceof ContentEntityFormInterface ? $formObject->getEntity() : NULL;

    foreach ($rows as $index => $row) {
      if ($row['recommended'] !== NULL) {
        $rows[$index]['recommended'] = [$row['recommended']];
        $rows[$index]['reason'] = 'unique';
        continue;
      }
      $rows[$index]['recommended'] = [];
      $rows[$index]['reason'] = NULL;
      if (!$lesson instanceof NodeInterface || $row['candidates'] === []) {
        continue;
      }
      $recommendation = $this->previousAttendance->recommendation($lesson, $row['candidates']);
      $rows[$index]['recommended'] = $recommendation['recommended'];
      $rows[$index]['reason'] = $recommendation['reason'];
    }

    return $rows;
  }

  /**
   * Adds the confirmed members of the review table to the participant field.
   *
   * @param array<mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function applyReviewSubmit(array &$form, FormStateInterface $form_state): void {
    if (!$this->isAvailable()) {
      return;
    }

    $triggeringElement = $form_state->getTriggeringElement();
    $fieldName = $triggeringElement['#field_widget_action_field_name'] ?? NULL;
    if (!is_string($fieldName) || $fieldName === '') {
      return;
    }

    $parents = $triggeringElement['#parents'] ?? [];
    if (!is_array($parents)) {
      $parents = [];
    }
    // Drop the button from the parents to reach the container holding the
    // table.
    array_pop($parents);
    $values = $form_state->getValue($parents);
    if (!is_array($values)) {
      $values = [];
    }

    $selected = [];
    $table = $values['table'] ?? NULL;
    foreach (is_array($table) ? $table : [] as $row) {
      if (!is_array($row)) {
        continue;
      }
      $ids = $row['mitglied'] ?? [];
      foreach (is_array($ids) ? $ids : [$ids] as $id) {
        if (is_array($id)) {
          $id = $id['target_id'] ?? NULL;
        }
        if (is_numeric($id) && (int) $id > 0) {
          $selected[(int) $id] = (int) $id;
        }
      }
    }

    // The editor has reviewed the proposals, so the table is no longer shown.
    $form_state->set($this->reviewKey($fieldName), []);

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
      $this->messengerService->addError($this->t('The participants could not be applied.'));
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
   * Returns the review rows of a field from the form state.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param string $fieldName
   *   The participant field name.
   *
   * @return array<int, array{name: string, candidates: int[], recommended: int[], reason: string|null}>
   *   The recognized text, candidates, recommended members and the reason per
   *   row.
   */
  private function reviewRows(FormStateInterface $form_state, string $fieldName): array {
    $stored = $form_state->get($this->reviewKey($fieldName));
    if (!is_array($stored)) {
      return [];
    }

    $rows = [];
    foreach ($stored as $row) {
      if (!is_array($row) || !isset($row['name']) || !is_string($row['name'])) {
        continue;
      }
      $candidates = [];
      foreach (is_array($row['candidates'] ?? NULL) ? $row['candidates'] : [] as $id) {
        if (is_int($id) || (is_string($id) && ctype_digit($id))) {
          $candidates[] = (int) $id;
        }
      }
      $recommended = [];
      foreach (is_array($row['recommended'] ?? NULL) ? $row['recommended'] : [] as $id) {
        if (is_int($id) || (is_string($id) && ctype_digit($id))) {
          $recommended[] = (int) $id;
        }
      }
      $reason = $row['reason'] ?? NULL;
      $rows[] = [
        'name' => $row['name'],
        'candidates' => $candidates,
        'recommended' => $recommended,
        'reason' => in_array($reason, ['unique', 'regular', 'previous'], TRUE) ? $reason : NULL,
      ];
    }
    return $rows;
  }

  /**
   * Returns the form state key that holds the review rows.
   *
   * @param string $fieldName
   *   The participant field name.
   *
   * @return string
   *   The form state key.
   */
  private function reviewKey(string $fieldName): string {
    return self::REVIEW_STATE_PREFIX . $fieldName;
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
