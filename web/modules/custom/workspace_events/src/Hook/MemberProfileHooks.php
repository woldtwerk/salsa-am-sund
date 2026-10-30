<?php

declare(strict_types=1);

namespace Drupal\workspace_events\Hook;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Link;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\user\UserInterface;
use Drupal\workspace_events\DanceFigureFlag;
use Drupal\workspace_events\MemberProgress;

/**
 * Shows the course and figure progress on a member profile.
 */
final class MemberProfileHooks {

  use StringTranslationTrait;

  /**
   * The extra fields of the user profile.
   *
   * @var string[]
   */
  private const array EXTRA_FIELDS = [
    'visited_courses',
    'learned_figures',
    'liked_figures',
  ];

  /**
   * Constructs the member profile hooks.
   *
   * @param \Drupal\workspace_events\MemberProgress $memberProgress
   *   The member progress.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $translation
   *   The translation service.
   */
  public function __construct(
    private readonly MemberProgress $memberProgress,
    private readonly AccountProxyInterface $currentUser,
    TranslationInterface $translation,
  ) {
    $this->stringTranslation = $translation;
  }

  /**
   * Implements hook_entity_extra_field_info().
   *
   * @return array<string, array<string, array<string, array<string, array<string, mixed>>>>>
   *   The extra fields.
   */
  #[Hook('entity_extra_field_info')]
  public function entityExtraFieldInfo(): array {
    $extra = [];
    foreach (self::EXTRA_FIELDS as $name) {
      $extra['user']['user']['display'][$name] = [
        'label' => $this->label($name),
        'description' => '',
        'weight' => 0,
        'visible' => TRUE,
      ];
    }
    return $extra;
  }

  /**
   * Implements hook_user_view().
   *
   * @param array<string, mixed> $build
   *   The render array of the profile.
   * @param \Drupal\user\UserInterface $account
   *   The user being viewed.
   * @param \Drupal\Core\Entity\Display\EntityViewDisplayInterface $display
   *   The view display.
   * @param string $view_mode
   *   The view mode.
   *
   * @param-out array<string, mixed> $build
   *   The render array of the profile after the hook ran.
   */
  #[Hook('user_view')]
  public function userView(array &$build, UserInterface $account, EntityViewDisplayInterface $display, string $view_mode): void {
    $sections = array_filter(
      self::EXTRA_FIELDS,
      static fn (string $name): bool => $display->getComponent($name) !== NULL,
    );
    if ($sections === []) {
      return;
    }

    $is_owner = $this->currentUser->isAuthenticated()
      && (int) $this->currentUser->id() === (int) $account->id();
    if (!$is_owner && !$this->currentUser->hasPermission(LessonAccessHooks::EDIT_PERMISSION)) {
      $metadata = CacheableMetadata::createFromRenderArray($build);
      $metadata->addCacheContexts(['user.permissions', 'user']);
      // @phpstan-ignore paramOut.type (applyTo() widens the array type.)
      $metadata->applyTo($build);
      return;
    }

    $uid = (int) $account->id();
    if (in_array('visited_courses', $sections, TRUE)) {
      $metadata = $this->metadata($account);
      $items = [];
      foreach ($this->memberProgress->visitedCourses($uid) as $visited) {
        $course = $visited['course'];
        $items[] = Link::fromTextAndUrl(
          $this->formatPlural(
            $visited['lessons'],
            '@title (@count lesson)',
            '@title (@count lessons)',
            ['@title' => (string) $course->label()],
          ),
          $course->toUrl(),
        )->toRenderable();
        $metadata->addCacheableDependency($course);
      }
      $build['visited_courses'] = $this->withCacheMetadata([
        '#type' => 'container',
        '#attributes' => ['class' => ['member-profile-section']],
        'list' => [
          '#theme' => 'item_list',
          '#title' => $this->label('visited_courses'),
          '#items' => $items,
          '#empty' => [
            '#type' => 'html_tag',
            '#tag' => 'p',
            '#value' => $this->t('No courses yet.'),
          ],
        ],
      ], $metadata);
    }

    if (in_array('learned_figures', $sections, TRUE)) {
      $build['learned_figures'] = $this->figureSection($account, DanceFigureFlag::Learned);
    }

    if (in_array('liked_figures', $sections, TRUE)) {
      $build['liked_figures'] = $this->figureSection($account, DanceFigureFlag::Like);
    }
  }

  /**
   * Builds the render array of a figure section.
   *
   * @param \Drupal\user\UserInterface $account
   *   The user being viewed.
   * @param \Drupal\workspace_events\DanceFigureFlag $flag
   *   The flag of the section.
   *
   * @return array<string, mixed>
   *   The render array.
   */
  private function figureSection(UserInterface $account, DanceFigureFlag $flag): array {
    $metadata = $this->metadata($account);
    $items = [];
    foreach ($this->memberProgress->flaggedFigures((int) $account->id(), $flag) as $term) {
      $items[] = Link::fromTextAndUrl((string) $term->label(), $term->toUrl())->toRenderable();
      $metadata->addCacheableDependency($term);
    }

    /** @var array<string, mixed> $section */
    $section = [
      '#type' => 'container',
      '#attributes' => ['class' => ['member-profile-section']],
      'list' => [
        '#theme' => 'item_list',
        '#title' => $this->label($flag === DanceFigureFlag::Learned ? 'learned_figures' : 'liked_figures'),
        '#items' => $items,
        '#empty' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('No figures yet.'),
        ],
      ],
    ];
    return $this->withCacheMetadata($section, $metadata);
  }

  /**
   * Adds the cache metadata to a render array.
   *
   * @param array<string, mixed> $section
   *   The render array.
   * @param \Drupal\Core\Cache\CacheableMetadata $metadata
   *   The cache metadata.
   *
   * @return array<string, mixed>
   *   The render array with cache metadata.
   */
  private function withCacheMetadata(array $section, CacheableMetadata $metadata): array {
    $section['#cache'] = [
      'contexts' => $metadata->getCacheContexts(),
      'tags' => $metadata->getCacheTags(),
      'max-age' => $metadata->getCacheMaxAge(),
    ];
    return $section;
  }

  /**
   * Returns the label of an extra field.
   *
   * @param string $name
   *   The field name.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The label.
   */
  private function label(string $name): TranslatableMarkup {
    return match ($name) {
      'visited_courses' => $this->t('Visited courses'),
      'learned_figures' => $this->t('Learned figures'),
      'liked_figures' => $this->t('Liked figures'),
      default => throw new \InvalidArgumentException(sprintf('Unknown member profile field %s.', $name)),
    };
  }

  /**
   * Returns the cache metadata shared by the profile sections.
   *
   * @param \Drupal\user\UserInterface $account
   *   The user being viewed.
   *
   * @return \Drupal\Core\Cache\CacheableMetadata
   *   The cache metadata.
   */
  private function metadata(UserInterface $account): CacheableMetadata {
    $metadata = new CacheableMetadata();
    $metadata->addCacheContexts(['user.permissions', 'user']);
    $metadata->addCacheTags([
      'node_list:lesson',
      'node_list:course',
      'flagging_list',
      'taxonomy_term_list:dance_figure',
    ]);
    $metadata->addCacheableDependency($account);
    return $metadata;
  }

}
