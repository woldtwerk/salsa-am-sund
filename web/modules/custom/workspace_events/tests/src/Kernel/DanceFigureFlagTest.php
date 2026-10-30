<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_events\Kernel;

use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Render\HtmlResponse;
use Drupal\flag\FlagInterface;
use Drupal\flag\FlagServiceInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\taxonomy\TermInterface;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use Drupal\workspace_events\DanceFigureFlag;
use Drupal\workspace_events\EventSubscriber\HtmxFlagResponseSubscriber;
use Drupal\workspace_events\Hook\DanceFigureAccessHooks;
use Drupal\workspace_events\Plugin\ActionLink\HtmxLink;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests the dance figure flags and their HTMX links.
 */
#[CoversClass(HtmxLink::class)]
#[CoversClass(HtmxFlagResponseSubscriber::class)]
#[CoversClass(DanceFigureFlag::class)]
#[Group('workspace_events')]
#[RunTestsInSeparateProcesses]
final class DanceFigureFlagTest extends EventKernelTestBase {

  /**
   * The modules the flag test needs.
   *
   * @var string[]
   */
  protected static $modules = ['flag'];

  /**
   * The tested flag.
   */
  private const DanceFigureFlag FLAG = DanceFigureFlag::Learned;

  /**
   * The flag service.
   */
  private FlagServiceInterface $flagService;

  /**
   * The dance figure term.
   */
  private TermInterface $term;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    Vocabulary::create([
      'vid' => DanceFigureAccessHooks::VOCABULARY,
      'name' => 'Tanzfiguren',
    ])->save();
    $this->installConfigRecords([self::FLAG->configName()]);

    $service = $this->container->get('flag');
    \assert($service instanceof FlagServiceInterface);
    $this->flagService = $service;

    $this->term = Term::create([
      'vid' => DanceFigureAccessHooks::VOCABULARY,
      'name' => 'Dile que no',
      'status' => 1,
    ]);
    $this->term->save();
  }

  /**
   * Tests the HTMX response and the cache contexts of the flag field.
   */
  public function testHtmxResponseAndCacheContexts(): void {
    $flag = $this->flagService->getFlagById(self::FLAG->value);
    self::assertInstanceOf(FlagInterface::class, $flag);
    $member = $this->createMember();
    $this->setCurrentUser($member);

    $response = $this->handleFlagRequest($this->term, 'flag');
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    self::assertInstanceOf(HtmlResponse::class, $response);
    $content = $response->getContent();
    self::assertIsString($content);
    $content = ltrim($content);
    self::assertStringStartsWith('<div', $content);
    self::assertStringContainsString('flag-' . self::FLAG->value, $content);
    self::assertStringContainsString($flag->getShortText('unflag'), $content);
    self::assertStringContainsString('href="/flag/unflag/' . self::FLAG->value . '/', $content);
    self::assertStringContainsString('data-hx-post=', $content);
    self::assertStringContainsString('data-hx-target="closest .flag"', $content);
    self::assertStringContainsString('data-hx-swap="outerHTML"', $content);
    self::assertStringNotContainsString('<html', $content);
    self::assertStringNotContainsString('<!DOCTYPE', $content);
    self::assertStringNotContainsString('<body', $content);
    self::assertTrue($flag->isFlagged($this->term, $member));

    $display = EntityViewDisplay::create([
      'targetEntityType' => 'taxonomy_term',
      'bundle' => DanceFigureAccessHooks::VOCABULARY,
      'mode' => 'full',
      'status' => TRUE,
    ]);
    $display->setComponent('flag_' . self::FLAG->value, [
      'weight' => 0,
      'region' => 'content',
    ]);
    $display->save();

    $builder = $this->container->get('entity_type.manager')->getViewBuilder('taxonomy_term');
    $build = $builder->build($builder->view($this->term, 'full'));
    self::assertArrayHasKey('flag_' . self::FLAG->value, $build);
    $contexts = $build['#cache']['contexts'] ?? [];
    self::assertIsArray($contexts);
    self::assertContains('user.permissions', $contexts);
    self::assertNotContains('user', $contexts);
  }

  /**
   * Creates a member with the dance figure permissions.
   *
   * @return \Drupal\user\UserInterface
   *   The member.
   */
  private function createMember(): UserInterface {
    $role = Role::load('member') ?? Role::create([
      'id' => 'member',
      'label' => 'Mitglied',
    ]);
    foreach ([
      'access content',
      DanceFigureAccessHooks::VIEW_PERMISSION,
      ...self::FLAG->permissions(),
    ] as $permission) {
      $role->grantPermission($permission);
    }
    $role->save();

    $user = User::create([
      'name' => $this->randomMachineName(),
      'status' => 1,
      'roles' => ['member'],
    ]);
    $user->save();
    return $user;
  }

  /**
   * Sends an HTMX request to the flag route.
   *
   * @param \Drupal\taxonomy\TermInterface $term
   *   The dance figure term.
   * @param string $action
   *   The action, "flag" or "unflag".
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response.
   */
  private function handleFlagRequest(TermInterface $term, string $action): Response {
    $path = $action === 'flag'
      ? 'flag/flag/' . self::FLAG->value . '/' . $term->id() . '/full'
      : 'flag/unflag/' . self::FLAG->value . '/' . $term->id() . '/full';
    $token = $this->container->get('csrf_token')->get($path);

    $request = Request::create('/' . $path, 'POST');
    $request->query->set('token', $token);
    $request->headers->set('HX-Request', 'true');

    return $this->container->get('http_kernel')->handle($request);
  }

}
