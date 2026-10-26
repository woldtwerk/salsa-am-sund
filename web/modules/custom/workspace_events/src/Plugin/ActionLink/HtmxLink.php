<?php

declare(strict_types=1);

namespace Drupal\workspace_events\Plugin\ActionLink;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Htmx\Htmx;
use Drupal\Core\Url;
use Drupal\flag\FlagInterface;
use Drupal\flag\Plugin\ActionLink\Reload;

/**
 * Provides the HTMX link type.
 *
 * The link is a normal GET link, so it still works without JavaScript. With
 * JavaScript, HTMX sends a POST request to the flag or unflag route and swaps
 * the link wrapper with the re-rendered link from the response.
 *
 * @ActionLinkType(
 *   id = "htmx",
 *   label = @Translation("HTMX link"),
 *   description = @Translation("The link is replaced in place without a page reload.")
 * )
 */
final class HtmxLink extends Reload {

  /**
   * {@inheritdoc}
   */
  public function getUrl($action, FlagInterface $flag, EntityInterface $entity) {
    // The href is the no-JavaScript route, so a click without HTMX reloads the
    // page and updates the flag through Flag's normal GET controller.
    return Url::fromRoute(
      $action === 'flag' ? 'flag.action_link_flag_nojs' : 'flag.action_link_unflag_nojs',
      [
        'flag' => $flag->id(),
        'entity_id' => $entity->id(),
      ],
    );
  }

  /**
   * {@inheritdoc}
   *
   * @return array<mixed>
   *   The render array of the link.
   */
  public function getAsFlagLink(FlagInterface $flag, EntityInterface $entity, ?string $view_mode = 'default'): array {
    $build = parent::getAsFlagLink($flag, $entity, $view_mode);
    $attributes = $build['#attributes'] ?? NULL;

    // Preview and new entities have no ID and no flag or unflag route yet.
    if ($entity->isNew() || $entity->id() === NULL || !is_array($attributes) || !isset($attributes['href'])) {
      return $build;
    }

    $action = $this->getAction($flag, $entity);
    $url = Url::fromRoute(
      $action === 'flag' ? 'flag.action_link_flag' : 'flag.action_link_unflag',
      [
        'flag' => $flag->id(),
        'entity_id' => $entity->id(),
        'view_mode' => $view_mode ?? 'default',
      ],
    );

    (new Htmx())
      ->post($url)
      // Flag's theme wraps the link in a <div class="flag">.
      ->target('closest .flag')
      ->swap('outerHTML', '', FALSE)
      ->applyTo($build);

    return $build;
  }

}
