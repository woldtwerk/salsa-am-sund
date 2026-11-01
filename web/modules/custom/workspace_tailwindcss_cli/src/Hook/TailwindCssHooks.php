<?php

declare(strict_types=1);

namespace Drupal\workspace_tailwindcss_cli\Hook;

use Drupal\Core\Asset\AttachedAssetsInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Language\LanguageInterface;
use Drupal\workspace_tailwindcss_cli\Asset\TailwindSourceHasher;

/**
 * Hook implementations for workspace_tailwindcss_cli.
 */
final class TailwindCssHooks {

  /**
   * Constructs the hook implementations.
   *
   * @param \Drupal\workspace_tailwindcss_cli\Asset\TailwindSourceHasher $sourceHasher
   *   The Tailwind source hasher.
   */
  public function __construct(
    private readonly TailwindSourceHasher $sourceHasher,
  ) {}

  /**
   * Implements hook_css_alter().
   *
   * Adds the Tailwind source hash as an extra key to compiled CSS assets.
   * Drupal builds CSS aggregate names from all asset item keys except weight,
   * type, group, media and browsers (see AssetGroupSetHashTrait::generateHash),
   * so this key makes the aggregate name change when a Tailwind template
   * changes. Without it the aggregate name only depends on the CSS file, and
   * the stale compiled aggregate keeps being served when only templates change.
   *
   * AssetResolver caches the result of hook_css_alter(), so the hash is
   * computed once per cache rebuild rather than on every request.
   *
   * @param array<string, array<string, mixed>> $css
   *   The CSS assets, keyed by asset identifier.
   * @param \Drupal\Core\Asset\AttachedAssetsInterface $assets
   *   The attached assets being rendered.
   * @param \Drupal\Core\Language\LanguageInterface $language
   *   The language of the page being rendered.
   */
  #[Hook('css_alter')]
  public function cssAlter(array &$css, AttachedAssetsInterface $assets, LanguageInterface $language): void {
    foreach ($css as $key => $item) {
      if (($item['type'] ?? NULL) !== 'file' || empty($item['preprocess']) || !is_string($item['data'] ?? NULL)) {
        continue;
      }

      $hash = $this->sourceHasher->hash($item['data']);
      if ($hash !== NULL) {
        $css[$key]['tailwind_sources'] = $hash;
      }
    }
  }

}
