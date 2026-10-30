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
use Drupal\taxonomy\TermInterface;
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
   * The wrapper classes of the dance figure flag buttons.
   *
   * Copied from the dance figure detail page, so the buttons look the same.
   */
  private const string FIGURE_FLAG_ACTIONS_CLASS = 'flex flex-wrap items-center gap-3'
    . ' [&_.flag]:contents'
    . ' [&_a]:inline-flex'
    . ' [&_a]:w-fit'
    . ' [&_a]:items-center'
    . ' [&_a]:justify-center'
    . ' [&_a]:gap-2.5'
    . ' [&_a]:rounded-full'
    . ' [&_a]:px-6'
    . ' [&_a]:py-3'
    . ' [&_a]:font-semibold'
    . ' [&_a]:no-underline'
    . ' [&_a]:transition'
    . ' [&_a]:duration-200'
    . ' [&_a]:ease-out'
    . ' [&_.flag-learned_a]:bg-flame-deep'
    . ' [&_.flag-learned_a]:text-white'
    . ' [&_.flag-learned_a]:shadow-[0_14px_34px_-16px_rgb(143_15_22_/_0.9)]'
    . ' [&_.flag-learned_a]:hover:brightness-95'
    . ' [&_.flag-learned_a]:hover:text-white'
    . ' [&_.flag-like_a]:border'
    . ' [&_.flag-like_a]:border-line'
    . ' [&_.flag-like_a]:bg-transparent'
    . ' [&_.flag-like_a]:text-sand'
    . ' [&_.flag-like_a]:hover:bg-flame-gold/10';

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
      $build['learned_figures'] = $this->figureSection($account, DanceFigureFlag::Learned, $is_owner);
    }

    if (in_array('liked_figures', $sections, TRUE)) {
      $build['liked_figures'] = $this->figureSection($account, DanceFigureFlag::Like, $is_owner);
    }
  }

  /**
   * Builds the render array of a figure section.
   *
   * @param \Drupal\user\UserInterface $account
   *   The user being viewed.
   * @param \Drupal\workspace_events\DanceFigureFlag $flag
   *   The flag of the section.
   * @param bool $show_flags
   *   Whether the viewer may toggle the flags of the figures.
   *
   * @return array<string, mixed>
   *   The render array.
   */
  private function figureSection(UserInterface $account, DanceFigureFlag $flag, bool $show_flags): array {
    $metadata = $this->metadata($account);
    $items = [];
    foreach ($this->memberProgress->flaggedFigures((int) $account->id(), $flag) as $term) {
      $item = [
        '#type' => 'container',
        '#attributes' => ['class' => ['flex', 'flex-wrap', 'items-center', 'gap-3']],
        'link' => Link::fromTextAndUrl((string) $term->label(), $term->toUrl())->toRenderable(),
      ];
      if ($show_flags) {
        $item['flags'] = [
          '#type' => 'container',
          '#attributes' => ['class' => [self::FIGURE_FLAG_ACTIONS_CLASS]],
          'learned' => $this->flagLink($term, DanceFigureFlag::Learned),
          'like' => $this->flagLink($term, DanceFigureFlag::Like),
        ];
      }
      $items[] = $item;
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
   * Builds the lazy placeholder of a figure flag link.
   *
   * @param \Drupal\taxonomy\TermInterface $term
   *   The dance figure term.
   * @param \Drupal\workspace_events\DanceFigureFlag $flag
   *   The flag.
   *
   * @return array<string, mixed>
   *   The render array of the flag link placeholder.
   */
  private function flagLink(TermInterface $term, DanceFigureFlag $flag): array {
    return [
      '#lazy_builder' => [
        'flag.link_builder:build',
        ['taxonomy_term', (int) $term->id(), $flag->value],
      ],
      '#create_placeholder' => TRUE,
    ];
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
