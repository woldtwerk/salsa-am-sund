<?php

declare(strict_types=1);

namespace Drupal\workspace_events\EventSubscriber;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Htmx\HtmxRequestInfoTrait;
use Drupal\Core\Render\HtmlResponse;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\flag\FlagInterface;
use Drupal\workspace_events\DanceFigureFlag;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Replaces Flag's AJAX response with the link markup for HTMX requests.
 *
 * Flag's controller re-renders the link after flagging or unflagging. HTMX
 * swaps that link into the page, so it needs the HTML of the link instead of
 * the AJAX command list. The controller and its flagging logic are reused;
 * only the response type changes. The subscriber only handles the dance figure
 * flags, so other flags keep their normal AJAX response.
 */
final class HtmxFlagResponseSubscriber implements EventSubscriberInterface {

  use HtmxRequestInfoTrait;

  /**
   * The Flag routes whose response is replaced for HTMX requests.
   */
  private const array ROUTES = [
    'flag.action_link_flag',
    'flag.action_link_unflag',
  ];

  /**
   * Constructs an HtmxFlagResponseSubscriber.
   *
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack.
   */
  public function __construct(
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * {@inheritdoc}
   */
  protected function getRequest(): Request {
    $request = $this->requestStack->getCurrentRequest();
    if ($request === NULL) {
      throw new \LogicException('No current request.');
    }
    return $request;
  }

  /**
   * Replaces the AJAX response of a Flag action with the rendered link.
   *
   * @param \Symfony\Component\HttpKernel\Event\ResponseEvent $event
   *   The response event.
   */
  public function onResponse(ResponseEvent $event): void {
    if (!$this->isHtmxRequest()) {
      return;
    }
    $request = $event->getRequest();
    $route_name = $request->attributes->get(RouteObjectInterface::ROUTE_NAME);
    if (!in_array($route_name, self::ROUTES, TRUE)) {
      return;
    }
    $flag = $request->attributes->get('flag');
    if (!$flag instanceof FlagInterface || !in_array($flag->id(), DanceFigureFlag::ids(), TRUE)) {
      return;
    }
    $response = $event->getResponse();
    if (!$response instanceof AjaxResponse) {
      return;
    }
    $markup = $this->getLinkMarkup($response);
    if ($markup === NULL) {
      return;
    }

    $event->setResponse(new HtmlResponse($markup));
  }

  /**
   * Returns the rendered link from Flag's AJAX replace command.
   *
   * @param \Drupal\Core\Ajax\AjaxResponse $response
   *   The response of Flag's controller.
   *
   * @return string|null
   *   The link markup, or NULL when the response has no replace command.
   */
  private function getLinkMarkup(AjaxResponse $response): ?string {
    foreach ($response->getCommands() as $command) {
      if (!is_array($command)) {
        continue;
      }
      $selector = $command['selector'] ?? NULL;
      if (($command['command'] ?? NULL) !== 'insert' || ($command['method'] ?? NULL) !== 'replaceWith' || !is_string($selector) || !str_starts_with($selector, '.js-flag-')) {
        continue;
      }
      $markup = $command['data'] ?? NULL;
      if (is_string($markup) || $markup instanceof MarkupInterface) {
        return (string) $markup;
      }
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Run high enough to let the response subscribers process the new HTML
    // response, but before AjaxResponseSubscriber (priority -100) serializes
    // the AJAX response as JSON.
    return [KernelEvents::RESPONSE => ['onResponse', 50]];
  }

}
