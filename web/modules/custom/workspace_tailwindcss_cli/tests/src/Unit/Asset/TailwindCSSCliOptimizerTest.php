<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_tailwindcss_cli\Unit\Asset;

use Drupal\Core\Extension\ExtensionPathResolver;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\workspace_tailwindcss_cli\Asset\TailwindCSSCliOptimizer;
use Drupal\workspace_tailwindcss_cli\Asset\TailwindSourceHasher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests TailwindCSSCliOptimizer strategy and binary resolution.
 */
#[CoversClass(TailwindCSSCliOptimizer::class)]
#[Group('workspace_tailwindcss_cli')]
class TailwindCSSCliOptimizerTest extends UnitTestCase {

  /**
   * Temporary base directory.
   */
  protected string $baseDir;

  /**
   * Application root within the temporary base directory.
   */
  protected string $appRoot;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->baseDir = sys_get_temp_dir() . '/workspace_tailwindcss_cli_' . $this->randomMachineName();
    $this->appRoot = $this->baseDir . '/app';
    mkdir($this->appRoot . '/modules/custom/workspace_tailwindcss_cli/node_modules/.bin', 0777, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->removeDirectory($this->baseDir);
    parent::tearDown();
  }

  /**
   * Tests binary resolution from the module's own node_modules.
   */
  public function testBinaryResolutionFromModule(): void {
    $binary = $this->appRoot . '/modules/custom/workspace_tailwindcss_cli/node_modules/.bin/tailwindcss';
    $this->writeExecutable($binary);

    $optimizer = $this->createOptimizer();
    $method = new \ReflectionMethod($optimizer, 'resolveBinary');

    $this->assertSame($binary, $method->invoke($optimizer));
  }

  /**
   * Tests the project-root fallback when the module binary is absent.
   */
  public function testBinaryResolutionFallsBackToProjectRoot(): void {
    $fallback = $this->baseDir . '/node_modules/.bin/tailwindcss';
    $this->writeExecutable($fallback);

    $optimizer = $this->createOptimizer();
    $method = new \ReflectionMethod($optimizer, 'resolveBinary');

    $this->assertSame($fallback, $method->invoke($optimizer));
  }

  /**
   * Creates the optimizer with test doubles.
   */
  protected function createOptimizer(): TailwindCSSCliOptimizer {
    $resolver = $this->createMock(ExtensionPathResolver::class);
    $resolver->method('getPath')
      ->with('module', 'workspace_tailwindcss_cli')
      ->willReturn('modules/custom/workspace_tailwindcss_cli');

    $logger = $this->createMock(LoggerChannelInterface::class);
    $fileUrlGenerator = $this->createMock(FileUrlGeneratorInterface::class);

    return new TailwindCSSCliOptimizer($fileUrlGenerator, $resolver, new TailwindSourceHasher($this->appRoot), $logger, $this->appRoot);
  }

  /**
   * Writes an executable stub.
   */
  protected function writeExecutable(string $path): void {
    if (!is_dir(dirname($path))) {
      mkdir(dirname($path), 0777, TRUE);
    }
    file_put_contents($path, "#!/bin/sh\n");
    chmod($path, 0755);
  }

  /**
   * Removes a directory recursively.
   */
  protected function removeDirectory(string $directory): void {
    if (!is_dir($directory)) {
      return;
    }
    $items = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
      \RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($items as $item) {
      if ($item->isDir()) {
        rmdir($item->getPathname());
      }
      else {
        unlink($item->getPathname());
      }
    }
    rmdir($directory);
  }

}
