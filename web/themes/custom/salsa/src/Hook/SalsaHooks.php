<?php

declare(strict_types=1);

namespace Drupal\salsa\Hook;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Template\Attribute;
use Drupal\Core\Url;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Entity\Page;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;
use Drupal\workspace_gallery\GalleryImages;
use Drupal\workspace_site\UserAvatar;
use Drupal\workspace_site\UserMenuBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Hook implementations for the Salsa am Sund theme.
 */
final class SalsaHooks {

  use StringTranslationTrait;

  /**
   * OpenID Connect client IDs mapped to their CoreUI brand icon ID.
   */
  /**
   * Node bundles whose full template renders its own full-width hero.
   *
   * Their pages are not wrapped in the route content container, so the hero
   * spans the whole viewport like the hero of a Canvas page.
   *
   * @var string[]
   */
  private const FULL_BLEED_BUNDLES = ['course', 'event'];

  private const PROVIDER_ICONS = [
    'google' => 'google',
    'github' => 'github',
    'facebook' => 'facebook',
  ];

  /**
   * OpenID Connect client IDs mapped to the label on the provider button.
   */
  private const PROVIDER_LABELS = [
    'google' => 'Google',
    'github' => 'GitHub',
    'facebook' => 'Meta',
  ];

  /**
   * Constructs the hook class.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $routeMatch
   *   The current route match.
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   *   The language manager.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $dateFormatter
   *   The date formatter.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\workspace_site\UserAvatar $userAvatar
   *   The avatar resolver.
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container, used to load optional module services lazily.
   */
  public function __construct(
    private readonly RouteMatchInterface $routeMatch,
    private readonly LanguageManagerInterface $languageManager,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly TimeInterface $time,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly UserAvatar $userAvatar,
    private readonly ContainerInterface $container,
  ) {}

  /**
   * Implements hook_preprocess_html().
   *
   * @param array<string, mixed> $variables
   *   The theme variables.
   */
  #[Hook('preprocess_html')]
  public function preprocessHtml(array &$variables): void {
    if (isset($variables['html_attributes']) && $variables['html_attributes'] instanceof Attribute) {
      $variables['html_attributes']->addClass('salsa-theme');
    }
  }

  /**
   * Implements hook_preprocess_node().
   *
   * @param array<mixed> $variables
   *   The theme variables.
   *
   * @param-out array<mixed> $variables
   */
  #[Hook('preprocess_node')]
  public function preprocessNode(array &$variables): void {
    $node = $variables['node'] ?? NULL;
    if (!$node instanceof NodeInterface || $node->bundle() !== 'gallery') {
      return;
    }
    $view_mode = $variables['view_mode'] ?? NULL;
    if (!is_string($view_mode) || !in_array($view_mode, ['full', 'card'], TRUE)) {
      return;
    }
    if (!$this->container->has(GalleryImages::class)) {
      return;
    }

    // A card only ever shows the first image, so avoid loading the entire
    // gallery for every teaser in the overview.
    $gallery_images = $this->container->get(GalleryImages::class);
    if (!$gallery_images instanceof GalleryImages) {
      return;
    }
    $result = $gallery_images->build($node, $view_mode === 'card' ? 1 : NULL);
    $variables['gallery_images'] = $result['images'];

    $count = $node->hasField('field_gallery_images') ? $node->get('field_gallery_images')->count() : 0;
    $variables['gallery_image_count'] = $count;
    $variables['gallery_image_count_label'] = $this->formatPlural($count, '@count image', '@count images');

    $cache = CacheableMetadata::createFromRenderArray($variables);
    $cache->merge($result['cache']);
    $cache->applyTo($variables);
  }

  /**
   * Implements hook_preprocess_page_title().
   *
   * @param array<string, mixed> $variables
   *   The theme variables.
   */
  #[Hook('preprocess_page_title')]
  public function preprocessPageTitle(array &$variables): void {
    $cache = $variables['#cache'] ?? [];
    $cache = is_array($cache) ? $cache : [];
    $contexts = $cache['contexts'] ?? [];
    $contexts = is_array($contexts) ? $contexts : [];
    $contexts[] = 'route';
    $cache['contexts'] = $contexts;
    $variables['#cache'] = $cache;

    // Canvas pages carry their own <h1> in the hero component, and profile
    // pages carry it in the profile component. The authentication card prints
    // the heading itself, so the page title would duplicate it.
    $route_name = $this->routeMatch->getRouteName();
    if ($this->isCanvasPageRoute() || in_array($route_name, [
      'entity.user.canonical',
      'user.login',
      'user.pass',
      'user.register',
    ], TRUE)) {
      $variables['title'] = '';
    }
  }

  /**
   * Implements hook_theme_suggestions_user_alter().
   *
   * @param array<int, string> $suggestions
   *   The theme suggestions.
   * @param array<string, mixed> $variables
   *   The theme variables.
   */
  #[Hook('theme_suggestions_user_alter')]
  public function themeSuggestionsUserAlter(array &$suggestions, array $variables): void {
    $elements = $variables['elements'] ?? NULL;
    $view_mode = $variables['view_mode'] ?? (is_array($elements) ? ($elements['#view_mode'] ?? NULL) : NULL);
    if ($view_mode === 'compact') {
      $suggestions[] = 'user__compact';
    }
  }

  /**
   * Implements hook_preprocess_user().
   *
   * @param array<string, mixed> $variables
   *   The theme variables.
   */
  #[Hook('preprocess_user')]
  public function preprocessUser(array &$variables): void {
    $user = $variables['user'] ?? NULL;
    if (!$user instanceof UserInterface) {
      $elements = $variables['elements'] ?? NULL;
      $user = is_array($elements) ? ($elements['#user'] ?? NULL) : NULL;
    }
    if (!$user instanceof UserInterface) {
      return;
    }

    $avatar = $this->userAvatar->get($user);
    $variables['salsa_initials'] = $avatar['initials'];
    $variables['salsa_profile_picture'] = $avatar['picture'];

    $created = (int) $user->getCreatedTime();
    if ($created > 0) {
      $date = $this->dateFormatter->format($created, 'custom', 'd.m.Y');
      $interval = $this->dateFormatter->formatInterval(
        max(0, $this->time->getRequestTime() - $created),
        2,
      );
      $variables['salsa_member_since'] = $this->t('Member since @date (@interval ago)', [
        '@date' => $date,
        '@interval' => $interval,
      ]);
    }
    else {
      $variables['salsa_member_since'] = '';
    }

    // The "vor ..." interval changes over time even when the profile is cached.
    $cache = $variables['#cache'] ?? [];
    $cache = is_array($cache) ? $cache : [];
    $metadata = CacheableMetadata::createFromRenderArray($variables);
    $metadata->merge($this->userAvatar->getCacheability($user));
    $metadata->setCacheMaxAge(3600);
    $cache['contexts'] = $metadata->getCacheContexts();
    $cache['tags'] = $metadata->getCacheTags();
    $cache['max-age'] = $metadata->getCacheMaxAge();
    $variables['#cache'] = $cache;
  }

  /**
   * Implements hook_preprocess_block().
   *
   * Renders the account menu block through a lazy builder so the header stays
   * shared and BigPipe fills in the current user's avatar and links.
   *
   * @param array<mixed> $variables
   *   The theme variables.
   *
   * @param-out array<mixed> $variables
   */
  #[Hook('preprocess_block')]
  public function preprocessBlock(array &$variables): void {
    if (($variables['plugin_id'] ?? NULL) !== 'system_menu_block:account') {
      return;
    }

    $configuration = $variables['configuration'] ?? [];
    $configuration = is_array($configuration) ? $configuration : [];
    $level = $configuration['level'] ?? 1;
    $level = is_numeric($level) ? (int) $level : 1;
    $depth = $configuration['depth'] ?? 1;
    $depth = is_numeric($depth) ? (int) $depth : 1;
    $expand_all_items = !empty($configuration['expand_all_items']);

    $variables['content'] = [
      '#lazy_builder' => [
        UserMenuBuilder::class . ':build',
        [$level, $depth, $expand_all_items],
      ],
      '#create_placeholder' => TRUE,
      '#cache' => [
        'contexts' => ['user'],
        'tags' => ['config:system.menu.account'],
        'max-age' => Cache::PERMANENT,
      ],
    ];

    // Only the placeholder varies per user. Keep user/session contexts off the
    // outer block so the rest of the header stays shareable.
    $cache = $variables['#cache'] ?? [];
    $cache = is_array($cache) ? $cache : [];
    $contexts = $cache['contexts'] ?? [];
    $contexts = is_array($contexts) ? $contexts : [];
    $cache['contexts'] = array_values(array_filter($contexts, static function (mixed $context): bool {
      if (!is_string($context)) {
        return TRUE;
      }
      return $context !== 'user' && $context !== 'session' && !str_starts_with($context, 'session.');
    }));
    $variables['#cache'] = $cache;
  }

  /**
   * Implements hook_preprocess_canvas_page_variant().
   *
   * Canvas replaces the marker with the route's main content. For routes that
   * are not Canvas pages or Canvas content templates, that content has no
   * layout container of its own (for example contact forms, user profiles and
   * 403/404 pages). Wrap the marker output in the same container as the page
   * title. Canvas pages and content-template renderings stay full-bleed.
   *
   * @param array<string, mixed> $variables
   *   The theme variables.
   */
  #[Hook('preprocess_canvas_page_variant')]
  public function preprocessCanvasPageVariant(array &$variables): void {
    $this->addRouteCacheContext($variables);

    $content = &$variables['content'];
    if (!is_array($content)) {
      return;
    }

    // Always use the default-language front page; the current interface
    // language may add a prefix such as /en that the logo must not inherit.
    $front_url = Url::fromRoute('<front>', [], [
      'language' => $this->languageManager->getDefaultLanguage(),
    ])->toString();
    $this->addConfigCacheTag($variables);
    $this->injectComponentProp($content, 'salsa:site-header', 'front_url', $front_url);

    if ($this->isCanvasRenderedRoute() || $this->isFullBleedNodeRoute()) {
      return;
    }

    $this->wrapMarkerContent($content);
  }

  /**
   * Implements hook_theme_suggestions_form_alter().
   *
   * @param array<int, string> $suggestions
   *   The theme suggestions.
   * @param array<string, mixed> $variables
   *   The theme variables.
   */
  #[Hook('theme_suggestions_form_alter')]
  public function themeSuggestionsFormAlter(array &$suggestions, array $variables): void {
    $element = $variables['element'] ?? NULL;
    $form_id = is_array($element) ? ($element['#form_id'] ?? NULL) : NULL;
    if (in_array($form_id, ['user_login_form', 'user_pass', 'user_register_form'], TRUE)) {
      $suggestions[] = 'form__user_auth';
    }
  }

  /**
   * Implements hook_theme_suggestions_form_element_alter().
   *
   * @param array<int, string> $suggestions
   *   The theme suggestions.
   * @param array<string, mixed> $variables
   *   The theme variables.
   */
  #[Hook('theme_suggestions_form_element_alter')]
  public function themeSuggestionsFormElementAlter(array &$suggestions, array $variables): void {
    $element = $variables['element'] ?? NULL;
    if (is_array($element) && ($element['#type'] ?? NULL) === 'password') {
      $suggestions[] = 'form_element__password';
    }
  }

  /**
   * Implements hook_theme_suggestions_input_alter().
   *
   * @param array<int, string> $suggestions
   *   The theme suggestions.
   * @param array<string, mixed> $variables
   *   The theme variables.
   */
  #[Hook('theme_suggestions_input_alter')]
  public function themeSuggestionsInputAlter(array &$suggestions, array $variables): void {
    $element = $variables['element'] ?? NULL;
    if (is_array($element) && !empty($element['#salsa_brand'])) {
      $suggestions[] = 'input__submit__brand';
    }
  }

  /**
   * Implements hook_preprocess_form().
   *
   * @param array<string, mixed> $variables
   *   The theme variables.
   *
   * @param-out array<string, mixed> $variables
   */
  #[Hook('preprocess_form')]
  public function preprocessForm(array &$variables): void {
    $element = $variables['element'] ?? NULL;
    if (!is_array($element)) {
      return;
    }

    $form_id = $element['#form_id'] ?? NULL;
    $auth = $this->buildAuthCard($form_id);
    if ($auth === NULL) {
      return;
    }
    $variables['salsa_auth'] = $auth;

    $sso = $element['#salsa_sso'] ?? NULL;
    if ($sso instanceof MarkupInterface || is_string($sso)) {
      $variables['salsa_sso'] = $sso;
    }
  }

  /**
   * Builds the heading, intro and secondary links of an authentication card.
   *
   * @param mixed $form_id
   *   The form ID, if known.
   *
   * @return array<string, mixed>|null
   *   The card variables, or NULL when the form is not an authentication form.
   */
  private function buildAuthCard(mixed $form_id): ?array {
    switch ($form_id) {
      case 'user_login_form':
        $links = [
          ['url' => Url::fromRoute('user.pass')->toString(), 'label' => $this->t('Forgot password?')],
        ];
        $register = $this->configFactory->get('user.settings')->get('register');
        if (in_array($register, [
          UserInterface::REGISTER_VISITORS,
          UserInterface::REGISTER_VISITORS_ADMINISTRATIVE_APPROVAL,
        ], TRUE)) {
          $links[] = ['url' => Url::fromRoute('user.register')->toString(), 'label' => $this->t('Create account')];
        }
        return [
          'heading' => $this->t('Sign in'),
          'intro' => $this->t('Sign in with your account.'),
          'links' => $links,
        ];

      case 'user_pass':
        return [
          'heading' => $this->t('Reset password'),
          'intro' => $this->t('Enter your username or email address.', [], ['context' => 'salsa']),
          'links' => [
            ['url' => Url::fromRoute('user.login')->toString(), 'label' => $this->t('Go to sign in')],
          ],
        ];

      case 'user_register_form':
        return [
          'heading' => $this->t('Create account'),
          'intro' => $this->t('Create an account to book courses and keep track of events.'),
          'links' => [
            ['url' => Url::fromRoute('user.login')->toString(), 'label' => $this->t('Go to sign in')],
          ],
        ];
    }

    return NULL;
  }

  /**
   * Implements hook_form_comment_form_alter().
   *
   * The theme prints comment forms without the text format guidelines and the
   * "About text formats" link, so strip them once the text format element has
   * been processed.
   *
   * @param array<mixed> $form
   *   The comment form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param string $form_id
   *   The form ID.
   *
   * @param-out array<mixed> $form
   */
  #[Hook('form_comment_form_alter')]
  public function formCommentFormAlter(array &$form, FormStateInterface $form_state, string $form_id): void {
    self::addTextFormatHelperCleanup($form);
  }

  /**
   * Implements hook_form_user_login_form_alter().
   *
   * @param array<mixed> $form
   *   The login form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param string $form_id
   *   The form ID.
   *
   * @param-out array<mixed> $form
   */
  #[Hook('form_user_login_form_alter')]
  public function formUserLoginFormAlter(array &$form, FormStateInterface $form_state, string $form_id): void {
    // The "Konto erstellen" link depends on the registration setting.
    CacheableMetadata::createFromRenderArray($form)
      ->addCacheTags(['config:user.settings'])
      ->applyTo($form);
    $this->prepareAuthForm($form);
    $this->prepareLoginFields($form, TRUE);
    $this->extractOpenIdConnectLoginForm($form);
  }

  /**
   * Implements hook_form_user_pass_alter().
   *
   * @param array<mixed> $form
   *   The password reset form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param string $form_id
   *   The form ID.
   *
   * @param-out array<mixed> $form
   */
  #[Hook('form_user_pass_alter')]
  public function formUserPassAlter(array &$form, FormStateInterface $form_state, string $form_id): void {
    // The card intro already explains where the reset link is sent.
    unset($form['mail']);
    $this->prepareLoginFields($form, FALSE);
    $this->prepareAuthForm($form);
  }

  /**
   * Implements hook_form_user_register_form_alter().
   *
   * @param array<mixed> $form
   *   The registration form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param string $form_id
   *   The form ID.
   *
   * @param-out array<mixed> $form
   */
  #[Hook('form_user_register_form_alter')]
  public function formUserRegisterFormAlter(array &$form, FormStateInterface $form_state, string $form_id): void {
    $this->prepareAuthForm($form);
  }

  /**
   * Implements hook_form_openid_connect_login_form_alter().
   *
   * The provider buttons sit above the password form. They show the provider
   * name only and get the matching CoreUI brand icon.
   *
   * @param array<mixed> $form
   *   The OpenID Connect login form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param string $form_id
   *   The form ID.
   *
   * @param-out array<mixed> $form
   */
  #[Hook('form_openid_connect_login_form_alter')]
  public function formOpenIdConnectLoginFormAlter(array &$form, FormStateInterface $form_state, string $form_id): void {
    self::addClass($form, 'flex flex-col gap-3');
    foreach (Element::children($form) as $key) {
      if (!is_string($key) && !is_int($key)) {
        continue;
      }
      $element = $form[$key] ?? NULL;
      if (!is_array($element) || ($element['#type'] ?? NULL) !== 'submit') {
        continue;
      }
      $client_id = is_string($element['#name'] ?? NULL) ? $element['#name'] : (is_string($key) ? $key : '');
      $value = $element['#value'] ?? '';
      $value = (is_string($value) || $value instanceof MarkupInterface) ? (string) $value : '';
      $label = self::PROVIDER_LABELS[$client_id] ?? $value;
      $element['#value'] = (string) preg_replace('/^Log in with /i', '', $label);
      $icon = self::PROVIDER_ICONS[$client_id] ?? NULL;
      if ($icon !== NULL) {
        $element['#salsa_brand'] = $icon;
      }
      $form[$key] = $element;
    }
  }

  /**
   * Adds placeholders and drops the help texts of the name/password fields.
   *
   * The placeholders carry the same information, so the descriptions are
   * removed on the login and password reset forms. The registration form keeps
   * its descriptions because they document the username rules.
   *
   * @param array<mixed> $form
   *   The form, passed by reference.
   * @param bool $with_password
   *   Whether the form also has a password field.
   *
   * @param-out array<mixed> $form
   */
  private function prepareLoginFields(array &$form, bool $with_password): void {
    $name = $form['name'] ?? NULL;
    if (is_array($name)) {
      unset($name['#description']);
      $attributes = $name['#attributes'] ?? [];
      $attributes = is_array($attributes) ? $attributes : [];
      $attributes['placeholder'] = $this->t('Enter username or email');
      $name['#attributes'] = $attributes;
      $form['name'] = $name;
    }
    if (!$with_password) {
      return;
    }
    $pass = $form['pass'] ?? NULL;
    if (is_array($pass)) {
      unset($pass['#description']);
      $attributes = $pass['#attributes'] ?? [];
      $attributes = is_array($attributes) ? $attributes : [];
      $attributes['placeholder'] = $this->t('Enter password');
      $pass['#attributes'] = $attributes;
      // Room for the view_password toggle inside the field.
      self::addClass($pass, 'pe-12');
      $form['pass'] = $pass;
    }
  }

  /**
   * Adds the full-width styling to an authentication form's submit button.
   *
   * @param array<mixed> $form
   *   The form, passed by reference.
   *
   * @param-out array<mixed> $form
   */
  private function prepareAuthForm(array &$form): void {
    $actions = $form['actions'] ?? NULL;
    if (!is_array($actions)) {
      return;
    }
    $submit = $actions['submit'] ?? NULL;
    if (!is_array($submit)) {
      return;
    }
    self::addClass($submit, 'w-full');
    $actions['submit'] = $submit;
    $form['actions'] = $actions;
  }

  /**
   * Moves the rendered OpenID Connect login form out of the form prefix.
   *
   * The openid_connect module prints its own login form above the password
   * form through #prefix. The card template puts that markup above the divider
   * instead.
   *
   * @param array<mixed> $form
   *   The login form, passed by reference.
   *
   * @param-out array<mixed> $form
   */
  private function extractOpenIdConnectLoginForm(array &$form): void {
    $prefix = $form['#prefix'] ?? NULL;
    if (!$prefix instanceof MarkupInterface && !is_string($prefix)) {
      return;
    }
    $markup = (string) $prefix;
    if (!str_contains($markup, 'openid-connect-login-form')) {
      return;
    }
    // Without an enabled client the form is empty; drop it entirely so no
    // empty provider block or divider is rendered.
    if (!str_contains($markup, 'openid-connect-client-')) {
      unset($form['#prefix']);
      return;
    }
    $form['#salsa_sso'] = $prefix;
    unset($form['#prefix']);
  }

  /**
   * Adds the guideline/help cleanup callback to all text format elements.
   *
   * Comment body fields nest the text format element inside a field widget
   * container, so the form tree is searched recursively.
   *
   * @param array<mixed> $element
   *   The form or form element, passed by reference.
   *
   * @param-out array<mixed> $element
   */
  private static function addTextFormatHelperCleanup(array &$element): void {
    if (($element['#type'] ?? NULL) === 'text_format') {
      $after_build = $element['#after_build'] ?? [];
      $after_build = is_array($after_build) ? $after_build : [];
      $after_build[] = [self::class, 'stripTextFormatHelpers'];
      $element['#after_build'] = $after_build;
      return;
    }

    foreach (Element::children($element) as $key) {
      if (!is_string($key) && !is_int($key)) {
        continue;
      }
      $child = $element[$key] ?? NULL;
      if (!is_array($child)) {
        continue;
      }
      self::addTextFormatHelperCleanup($child);
      $element[$key] = $child;
    }
  }

  /**
   * Adds CSS classes to a form element's attributes.
   *
   * @param array<mixed> $element
   *   The form or form element, passed by reference.
   * @param string $class
   *   The CSS classes to append.
   *
   * @param-out array<mixed> $element
   */
  private static function addClass(array &$element, string $class): void {
    $attributes = $element['#attributes'] ?? [];
    $attributes = is_array($attributes) ? $attributes : [];
    $classes = $attributes['class'] ?? [];
    $classes = is_array($classes) ? $classes : [];
    $classes[] = $class;
    $attributes['class'] = $classes;
    $element['#attributes'] = $attributes;
  }

  /**
   * After-build callback: removes the text format guidelines and help.
   *
   * When only one text format is available, core hides the format selector but
   * still prints the wrapper around the guidelines. Hide that wrapper as well;
   * its children keep their #value, so the text format is still submitted.
   *
   * @param array<mixed> $element
   *   The text format element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array<mixed>
   *   The altered text format element.
   */
  public static function stripTextFormatHelpers(array $element, FormStateInterface $form_state): array {
    $format = $element['format'] ?? NULL;
    if (!is_array($format)) {
      return $element;
    }

    unset($format['guidelines'], $format['help']);

    $selector = $format['format'] ?? NULL;
    if (is_array($selector) && empty($selector['#access'])) {
      $format['#access'] = FALSE;
    }

    $element['format'] = $format;
    return $element;
  }

  /**
   * Adds the site configuration cache tag to the render variables.
   *
   * @param array<string, mixed> $variables
   *   The theme variables.
   */
  private function addConfigCacheTag(array &$variables): void {
    $cache = $variables['#cache'] ?? [];
    $cache = is_array($cache) ? $cache : [];
    $tags = $cache['tags'] ?? [];
    $tags = is_array($tags) ? $tags : [];
    $tags[] = 'config:system.site';
    $cache['tags'] = $tags;
    $variables['#cache'] = $cache;
  }

  /**
   * Injects a prop into the first matching component in a render tree.
   *
   * @param array<mixed> $content
   *   The render tree.
   * @param string $component_id
   *   The SDC component ID.
   * @param string $prop
   *   The prop name.
   * @param string $value
   *   The prop value.
   *
   * @return bool
   *   TRUE if a matching component was found.
   */
  private function injectComponentProp(array &$content, string $component_id, string $prop, string $value): bool {
    foreach ($content as &$item) {
      if (!is_array($item)) {
        continue;
      }
      $component = $item['#component'] ?? NULL;
      if (is_array($component) && ($component['#component'] ?? NULL) === $component_id) {
        $props = $component['#props'] ?? NULL;
        if (is_array($props)) {
          $props[$prop] = $value;
          $component['#props'] = $props;
          $item['#component'] = $component;
          unset($item);
          return TRUE;
        }
      }
      if ($this->injectComponentProp($item, $component_id, $prop, $value)) {
        unset($item);
        return TRUE;
      }
    }
    unset($item);
    return FALSE;
  }

  /**
   * Adds the route cache context to a render array's variables.
   *
   * @param array<string, mixed> $variables
   *   The theme variables.
   */
  private function addRouteCacheContext(array &$variables): void {
    $cache = $variables['#cache'] ?? [];
    $cache = is_array($cache) ? $cache : [];
    $contexts = $cache['contexts'] ?? [];
    $contexts = is_array($contexts) ? $contexts : [];
    $contexts[] = 'route';
    $cache['contexts'] = $contexts;
    $variables['#cache'] = $cache;
  }

  /**
   * Checks whether the route renders a node with its own full-width hero.
   *
   * Covers the canonical, revision and preview routes, which all render the
   * node's full template.
   *
   * @return bool
   *   TRUE for course and event nodes.
   */
  private function isFullBleedNodeRoute(): bool {
    foreach ($this->routeMatch->getParameters() as $parameter) {
      if ($parameter instanceof NodeInterface && in_array($parameter->bundle(), self::FULL_BLEED_BUNDLES, TRUE)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Determines whether the current route renders a Canvas page.
   *
   * The Canvas editor preview loads the page through the Canvas API layout
   * routes, so the page entity is a route parameter instead of the route name.
   */
  private function isCanvasPageRoute(): bool {
    if ($this->routeMatch->getRouteName() === 'entity.canvas_page.canonical') {
      return TRUE;
    }

    foreach ($this->routeMatch->getParameters() as $parameter) {
      if ($parameter instanceof Page) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Determines whether the current route is rendered by Canvas itself.
   */
  private function isCanvasRenderedRoute(): bool {
    if ($this->isCanvasPageRoute()) {
      return TRUE;
    }

    if (!class_exists(ContentTemplate::class)) {
      return FALSE;
    }

    foreach ($this->routeMatch->getParameters() as $parameter) {
      if (!$parameter instanceof FieldableEntityInterface) {
        continue;
      }
      $template = ContentTemplate::loadForEntity($parameter, 'full');
      if ($template !== NULL && $template->status()) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Wraps the Canvas "Page content" marker render array in a layout container.
   *
   * @param array<mixed> $content
   *   The page variant render array, passed by reference.
   */
  private function wrapMarkerContent(array &$content): bool {
    foreach ($content as &$item) {
      if (!is_array($item)) {
        continue;
      }

      $component = $item['#component'] ?? NULL;
      if (is_array($component) && ($component['#component'] ?? NULL) === 'salsa:page-main') {
        $slots = $component['#slots'] ?? NULL;
        if (!is_array($slots) || !isset($slots['content']) || !is_array($slots['content'])) {
          continue;
        }
        foreach ($slots['content'] as &$slot_item) {
          if (!is_array($slot_item)) {
            continue;
          }
          $slot_component = $slot_item['#component'] ?? NULL;
          // The marker is the only page-main slot item that renders plain
          // markup instead of a block component.
          if (is_array($slot_component) && isset($slot_component['#markup'])) {
            $slot_item = [
              '#type' => 'container',
              '#attributes' => ['class' => ['salsa-route-content']],
              'content' => $slot_item,
            ];
            unset($slot_item);
            $component['#slots'] = $slots;
            $item['#component'] = $component;
            return TRUE;
          }
        }
        unset($slot_item);
      }

      if ($this->wrapMarkerContent($item)) {
        return TRUE;
      }
    }
    unset($item);

    return FALSE;
  }

}
