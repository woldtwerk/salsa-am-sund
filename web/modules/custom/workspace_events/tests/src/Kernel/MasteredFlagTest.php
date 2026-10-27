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
use Drupal\workspace_events\DanceFigureFlag;
use Drupal\workspace_events\DanceFigureInstaller;
use Drupal\workspace_events\EventSubscriber\HtmxFlagResponseSubscriber;
use Drupal\workspace_events\Hook\DanceFigureAccessHooks;
use Drupal\workspace_events\Plugin\ActionLink\HtmxLink;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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
final class MasteredFlagTest extends EventKernelTestBase {

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

    $config_names = array_map(
      static fn (DanceFigureFlag $flag): string => $flag->configName(),
      DanceFigureFlag::cases(),
    );
    $installer = $this->container->get(DanceFigureInstaller::class);
    \assert($installer instanceof DanceFigureInstaller);
    $installer->install($config_names);

    $flag_service = $this->container->get('flag');
    \assert($flag_service instanceof FlagServiceInterface);
    $this->flagService = $flag_service;

    $this->term = Term::create([
      'vid' => DanceFigureAccessHooks::VOCABULARY,
      'name' => 'Dile que no',
      'status' => 1,
    ]);
    $this->term->save();
  }

  /**
   * Provides the dance figure flags.
   *
   * @return array<string, array{\Drupal\workspace_events\DanceFigureFlag}>
   *   The flag of each test case, keyed by the flag ID.
   */
  public static function flagProvider(): array {
    $flags = [];
    foreach (DanceFigureFlag::cases() as $flag) {
      $flags[$flag->value] = [$flag];
    }
    return $flags;
  }

  /**
   * Tests that a member can flag and unflag a dance figure.
   */
  #[DataProvider('flagProvider')]
  public function testMemberCanFlagAndUnflag(DanceFigureFlag $flag): void {
    $flag_entity = $this->getFlag($flag);
    $member = $this->createMember();
    $this->setCurrentUser($member);

    self::assertTrue($flag_entity->actionAccess('flag', $member, $this->term)->isAllowed());

    $this->flagService->flag($flag_entity, $this->term);
    self::assertTrue($flag_entity->isFlagged($this->term, $member));

    $this->flagService->unflag($flag_entity, $this->term);
    self::assertFalse($flag_entity->isFlagged($this->term, $member));
  }

  /**
   * Tests that anonymous users cannot flag a dance figure.
   */
  #[DataProvider('flagProvider')]
  public function testAnonymousCannotFlag(DanceFigureFlag $flag): void {
    $flag_entity = $this->getFlag($flag);
    $anonymous = User::getAnonymousUser();
    $this->setCurrentUser($anonymous);

    self::assertFalse($flag_entity->actionAccess('flag', $anonymous, $this->term)->isAllowed());

    $response = $this->handleFlagRequest($this->term, 'flag', $flag);
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
  #[DataProvider('flagProvider')]
  public function testHtmxResponseContainsOnlyTheLink(DanceFigureFlag $flag): void {
    $flag_entity = $this->getFlag($flag);
    $member = $this->createMember();
    $this->setCurrentUser($member);

    $response = $this->handleFlagRequest($this->term, 'flag', $flag);

    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    self::assertInstanceOf(HtmlResponse::class, $response);
    $content = $response->getContent();
    self::assertIsString($content);
    $content = ltrim($content);
    self::assertStringStartsWith('<div', $content);
    self::assertStringContainsString('flag-' . $flag->value, $content);
    self::assertStringContainsString($flag_entity->getShortText('unflag'), $content);
    self::assertStringContainsString('href="/flag/unflag/' . $flag->value . '/', $content);
    self::assertStringContainsString('data-hx-post=', $content);
    self::assertStringContainsString('data-hx-target="closest .flag"', $content);
    self::assertStringContainsString('data-hx-swap="outerHTML"', $content);
    self::assertStringNotContainsString('<html', $content);
    self::assertStringNotContainsString('<!DOCTYPE', $content);
    self::assertStringNotContainsString('<body', $content);
    self::assertTrue($flag_entity->isFlagged($this->term, $member));
  }

  /**
   * Tests that flaggings and links are per user.
   */
  #[DataProvider('flagProvider')]
  public function testFlaggingIsPerUser(DanceFigureFlag $flag): void {
    $flag_entity = $this->getFlag($flag);
    $first = $this->createMember();
    $second = $this->createMember();

    $this->setCurrentUser($first);
    $this->flagService->flag($flag_entity, $this->term);
    self::assertTrue($flag_entity->isFlagged($this->term, $first));
    self::assertFalse($flag_entity->isFlagged($this->term, $second));

    $this->setCurrentUser($first);
    self::assertStringContainsString($flag_entity->getShortText('unflag'), $this->renderLink($flag));

    $this->setCurrentUser($second);
    self::assertStringContainsString($flag_entity->getShortText('flag'), $this->renderLink($flag));
  }

  /**
   * Tests that the flag field does not add the per-user cache context.
   */
  #[DataProvider('flagProvider')]
  public function testFlagFieldUsesPermissionsButNotUserCacheContext(DanceFigureFlag $flag): void {
    $member = $this->createMember();
    $this->setCurrentUser($member);

    $display = EntityViewDisplay::create([
      'targetEntityType' => 'taxonomy_term',
      'bundle' => DanceFigureAccessHooks::VOCABULARY,
      'mode' => 'full',
      'status' => TRUE,
    ]);
    $display->setComponent('flag_' . $flag->value, [
      'weight' => 0,
      'region' => 'content',
    ]);
    $display->save();

    $builder = $this->container->get('entity_type.manager')
      ->getViewBuilder('taxonomy_term');
    $build = $builder->build($builder->view($this->term, 'full'));

    self::assertArrayHasKey('flag_' . $flag->value, $build);
    $contexts = $build['#cache']['contexts'] ?? [];
    self::assertIsArray($contexts);
    self::assertContains('user.permissions', $contexts);
    self::assertNotContains('user', $contexts);
  }

  /**
   * Tests that the shipped configuration displays the flag and grants access.
   */
  #[DataProvider('flagProvider')]
  public function testShippedDisplayAndPermissions(DanceFigureFlag $flag): void {
    $flag_entity = $this->getFlag($flag);
    $resolver = $this->container->get(ExtensionPathResolver::class);
    \assert($resolver instanceof ExtensionPathResolver);
    $storage = new FileStorage($resolver->getPath('module', 'workspace_events') . '/config/install');

    self::assertContains('workspace_events', $flag_entity->getDependencies()['module'] ?? []);

    $config = $storage->read($flag->configName());
    self::assertIsArray($config);
    self::assertSame($flag->value, $config['id']);
    self::assertSame('htmx', $config['link_type']);

    $display = $storage->read('core.entity_view_display.taxonomy_term.dance_figure.default');
    self::assertIsArray($display);
    self::assertArrayHasKey('flag_' . $flag->value, $display['content']);

    $role = $storage->read('user.role.member');
    self::assertIsArray($role);
    self::assertContains('flag ' . $flag->value, $role['permissions']);
    self::assertContains('unflag ' . $flag->value, $role['permissions']);
  }

  /**
   * Returns a dance figure flag.
   *
   * @param \Drupal\workspace_events\DanceFigureFlag $flag
   *   The dance figure flag.
   *
   * @return \Drupal\flag\FlagInterface
   *   The flag entity.
   */
  private function getFlag(DanceFigureFlag $flag): FlagInterface {
    $flag_entity = $this->flagService->getFlagById($flag->value);
    self::assertInstanceOf(FlagInterface::class, $flag_entity);
    return $flag_entity;
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
    ];
    foreach (DanceFigureFlag::cases() as $flag) {
      $permissions = array_merge($permissions, $flag->permissions());
    }
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
   * @param \Drupal\workspace_events\DanceFigureFlag $flag
   *   The dance figure flag.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response.
   */
  private function handleFlagRequest(TermInterface $term, string $action, DanceFigureFlag $flag): Response {
    $path = $action === 'flag'
      ? 'flag/flag/' . $flag->value . '/' . $term->id() . '/full'
      : 'flag/unflag/' . $flag->value . '/' . $term->id() . '/full';
    $token = $this->container->get('csrf_token')->get($path);

    $request = Request::create('/' . $path, 'POST');
    $request->query->set('token', $token);
    $request->headers->set('HX-Request', 'true');

    return $this->container->get('http_kernel')->handle($request);
  }

  /**
   * Renders the flag link for the current user.
   *
   * @param \Drupal\workspace_events\DanceFigureFlag $flag
   *   The dance figure flag.
   *
   * @return string
   *   The rendered link.
   */
  private function renderLink(DanceFigureFlag $flag): string {
    $flag_entity = $this->getFlag($flag);
    $build = $flag_entity->getLinkTypePlugin()
      ->getAsFlagLink($flag_entity, $this->term, 'full');
    return (string) $this->container->get('renderer')->renderInIsolation($build);
  }

}
