<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_events\Kernel;

use Drupal\Core\Config\FileStorage;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Extension\ExtensionPathResolver;
use Drupal\Core\Render\HtmlResponse;
use Drupal\flag\FlagInterface;
use Drupal\flag\FlagServiceInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\taxonomy\TermInterface;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use Drupal\workspace_events\DanceFigureInstaller;
use Drupal\workspace_events\EventSubscriber\HtmxFlagResponseSubscriber;
use Drupal\workspace_events\Hook\DanceFigureAccessHooks;
use Drupal\workspace_events\MasteredFlag;
use Drupal\workspace_events\Plugin\ActionLink\HtmxLink;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests the mastered flag and its HTMX link.
 */
#[CoversClass(HtmxLink::class)]
#[CoversClass(HtmxFlagResponseSubscriber::class)]
#[CoversClass(MasteredFlag::class)]
#[Group('workspace_events')]
#[RunTestsInSeparateProcesses]
final class MasteredFlagTest extends EventKernelTestBase {

  /**
   * The mastered flag.
   */
  private FlagInterface $flag;

  /**
   * The flag service.
   */
  private FlagServiceInterface $flagService;

  /**
   * The dance figure term used by the tests.
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

    $installer = $this->container->get(DanceFigureInstaller::class);
    \assert($installer instanceof DanceFigureInstaller);
    $installer->install([MasteredFlag::CONFIG_NAME]);

    $flag_service = $this->container->get('flag');
    \assert($flag_service instanceof FlagServiceInterface);
    $this->flagService = $flag_service;
    $flag = $this->flagService->getFlagById(MasteredFlag::FLAG_ID);
    self::assertInstanceOf(FlagInterface::class, $flag);
    $this->flag = $flag;

    $this->term = Term::create([
      'vid' => DanceFigureAccessHooks::VOCABULARY,
      'name' => 'Dile que no',
      'status' => 1,
    ]);
    $this->term->save();
  }

  /**
   * Tests that a member can flag and unflag a dance figure.
   */
  public function testMemberCanFlagAndUnflag(): void {
    $member = $this->createMember();
    $this->setCurrentUser($member);

    self::assertTrue($this->flag->actionAccess('flag', $member, $this->term)->isAllowed());

    $this->flagService->flag($this->flag, $this->term);
    self::assertTrue($this->flag->isFlagged($this->term, $member));

    $this->flagService->unflag($this->flag, $this->term);
    self::assertFalse($this->flag->isFlagged($this->term, $member));
  }

  /**
   * Tests that anonymous users cannot flag a dance figure.
   */
  public function testAnonymousCannotFlag(): void {
    $anonymous = User::getAnonymousUser();
    $this->setCurrentUser($anonymous);

    self::assertFalse($this->flag->actionAccess('flag', $anonymous, $this->term)->isAllowed());

    $response = $this->handleFlagRequest($this->term, 'flag');
    self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());

    $count = $this->container->get('entity_type.manager')
      ->getStorage('flagging')
      ->getQuery()
      ->accessCheck(FALSE)
      ->count()
      ->execute();
    self::assertSame(0, (int) $count);
  }

  /**
   * Tests that an HTMX request returns only the re-rendered link.
   */
  public function testHtmxResponseContainsOnlyTheLink(): void {
    $member = $this->createMember();
    $this->setCurrentUser($member);

    $response = $this->handleFlagRequest($this->term, 'flag');

    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    self::assertInstanceOf(HtmlResponse::class, $response);
    $content = $response->getContent();
    self::assertIsString($content);
    $content = ltrim($content);
    self::assertStringStartsWith('<div', $content);
    self::assertStringContainsString('flag-mastered', $content);
    self::assertStringContainsString('Gemeistert ✓', $content);
    self::assertStringContainsString('href="/flag/unflag/mastered/', $content);
    self::assertStringContainsString('data-hx-post=', $content);
    self::assertStringContainsString('data-hx-target="closest .flag"', $content);
    self::assertStringContainsString('data-hx-swap="outerHTML"', $content);
    self::assertStringNotContainsString('<html', $content);
    self::assertStringNotContainsString('<!DOCTYPE', $content);
    self::assertStringNotContainsString('<body', $content);
    self::assertTrue($this->flag->isFlagged($this->term, $member));
  }

  /**
   * Tests that flaggings and links are per user.
   */
  public function testFlaggingIsPerUser(): void {
    $first = $this->createMember();
    $second = $this->createMember();

    $this->setCurrentUser($first);
    $this->flagService->flag($this->flag, $this->term);
    self::assertTrue($this->flag->isFlagged($this->term, $first));
    self::assertFalse($this->flag->isFlagged($this->term, $second));

    $this->setCurrentUser($first);
    self::assertStringContainsString('Gemeistert ✓', $this->renderLink());

    $this->setCurrentUser($second);
    self::assertStringContainsString('Als gemeistert markieren', $this->renderLink());
  }

  /**
   * Tests that the flag field does not add the per-user cache context.
   */
  public function testFlagFieldUsesPermissionsButNotUserCacheContext(): void {
    $member = $this->createMember();
    $this->setCurrentUser($member);

    $display = EntityViewDisplay::create([
      'targetEntityType' => 'taxonomy_term',
      'bundle' => DanceFigureAccessHooks::VOCABULARY,
      'mode' => 'full',
      'status' => TRUE,
    ]);
    $display->setComponent('flag_' . MasteredFlag::FLAG_ID, [
      'weight' => 0,
      'region' => 'content',
    ]);
    $display->save();

    $builder = $this->container->get('entity_type.manager')
      ->getViewBuilder('taxonomy_term');
    $build = $builder->build($builder->view($this->term, 'full'));

    self::assertArrayHasKey('flag_' . MasteredFlag::FLAG_ID, $build);
    $contexts = $build['#cache']['contexts'] ?? [];
    self::assertIsArray($contexts);
    self::assertContains('user.permissions', $contexts);
    self::assertNotContains('user', $contexts);
  }

  /**
   * Tests that the shipped configuration displays the flag and grants access.
   */
  public function testShippedDisplayAndPermissions(): void {
    $resolver = $this->container->get(ExtensionPathResolver::class);
    \assert($resolver instanceof ExtensionPathResolver);
    $storage = new FileStorage($resolver->getPath('module', 'workspace_events') . '/config/install');

    self::assertContains('workspace_events', $this->flag->getDependencies()['module'] ?? []);

    $display = $storage->read('core.entity_view_display.taxonomy_term.dance_figure.default');
    self::assertIsArray($display);
    self::assertArrayHasKey('flag_' . MasteredFlag::FLAG_ID, $display['content']);

    $role = $storage->read('user.role.member');
    self::assertIsArray($role);
    self::assertContains('flag ' . MasteredFlag::FLAG_ID, $role['permissions']);
    self::assertContains('unflag ' . MasteredFlag::FLAG_ID, $role['permissions']);
  }

  /**
   * Creates a member with the dance figure permissions.
   *
   * @return \Drupal\user\UserInterface
   *   The member.
   */
  private function createMember(): UserInterface {
    $role = Role::load('member');
    if ($role === NULL) {
      $role = Role::create([
        'id' => 'member',
        'label' => 'Mitglied',
      ]);
      $role->save();
    }
    $permissions = [
      'access content',
      DanceFigureAccessHooks::VIEW_PERMISSION,
      ...MasteredFlag::PERMISSIONS,
    ];
    foreach ($permissions as $permission) {
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
   * Sends an HTMX request to the flag or unflag route.
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
      ? 'flag/flag/' . MasteredFlag::FLAG_ID . '/' . $term->id() . '/full'
      : 'flag/unflag/' . MasteredFlag::FLAG_ID . '/' . $term->id() . '/full';
    $token = $this->container->get('csrf_token')->get($path);

    $request = Request::create('/' . $path, 'POST');
    $request->query->set('token', $token);
    $request->headers->set('HX-Request', 'true');

    return $this->container->get('http_kernel')->handle($request);
  }

  /**
   * Renders the flag link for the current user.
   *
   * @return string
   *   The rendered link.
   */
  private function renderLink(): string {
    $build = $this->flag->getLinkTypePlugin()
      ->getAsFlagLink($this->flag, $this->term, 'full');
    return (string) $this->container->get('renderer')->renderInIsolation($build);
  }

}
