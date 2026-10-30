<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_events\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\workspace_events\Controller\EventsJsonController;
use Drupal\workspace_events\EventFilterFactory;
use Drupal\workspace_events\EventOccurrenceRepository;
use Drupal\workspace_events\LessonCourseResolver;
use Drupal\workspace_events\OrganizerLookupInterface;
use Drupal\workspace_events\SiteTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests the range validation of the JSON endpoint.
 */
#[CoversClass(EventsJsonController::class)]
#[Group('workspace_events')]
final class EventsJsonControllerTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // The response validates the cache contexts it adds.
    $cacheContextsManager = $this->createMock(CacheContextsManager::class);
    $cacheContextsManager->method('assertValidTokens')->willReturn(TRUE);
    $container = new ContainerBuilder();
    $container->set('cache_contexts_manager', $cacheContextsManager);
    \Drupal::setContainer($container);
  }

  /**
   * Tests that invalid ranges are rejected.
   */
  public function testInvalidRanges(): void {
    $start = new \DateTimeImmutable('2026-01-01T00:00:00+01:00');
    $end = $start->modify('+10 days');
    $invalid = [
      [],
      ['start' => $start->format(\DateTimeInterface::ATOM)],
      ['end' => $end->format(\DateTimeInterface::ATOM)],
      ['start' => 'gestern', 'end' => $end->format(\DateTimeInterface::ATOM)],
      ['start' => $start->format('Y-m-d'), 'end' => $start->format('Y-m-d')],
      [
        'start' => $start->format('Y-m-d'),
        'end' => $start->modify('+101 days')->format('Y-m-d'),
      ],
    ];

    foreach ($invalid as $query) {
      $response = $this->request($query);
      self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode(), json_encode($query, JSON_THROW_ON_ERROR));
      $body = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
      self::assertArrayHasKey('error', $body);
    }

    // Exactly 100 days are allowed.
    $response = $this->request([
      'start' => $start->format('Y-m-d'),
      'end' => $start->modify('+100 days')->format('Y-m-d'),
    ]);
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
  }

  /**
   * Builds the controller with an empty occurrence repository.
   *
   * @return \Drupal\workspace_events\Controller\EventsJsonController
   *   The controller.
   */
  private function controller(): EventsJsonController {
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('sort')->willReturnSelf();
    $query->method('execute')->willReturn([]);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->with('node')->willReturn($storage);

    return new EventsJsonController(
      new EventOccurrenceRepository(
        $entity_type_manager,
        $this->siteTimeZone(),
        new LessonCourseResolver($entity_type_manager),
      ),
      new EventFilterFactory($this->createMock(OrganizerLookupInterface::class)),
    );
  }

  /**
   * Builds the site timezone service for the test.
   *
   * @return \Drupal\workspace_events\SiteTimeZone
   *   The timezone service.
   */
  private function siteTimeZone(): SiteTimeZone {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->with('timezone.default')->willReturn('UTC');
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')->with('system.date')->willReturn($config);
    return new SiteTimeZone($config_factory, $this->createMock(TimeInterface::class));
  }

  /**
   * Performs a request against the controller.
   *
   * @param array<string, string> $query
   *   The query arguments.
   */
  private function request(array $query): Response {
    return $this->controller()->__invoke(
      Request::create('https://salsa-am-sund.localhost/termine.json', 'GET', $query),
    );
  }

}
