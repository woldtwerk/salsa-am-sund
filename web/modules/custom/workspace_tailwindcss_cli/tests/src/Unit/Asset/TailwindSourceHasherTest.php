<?php

declare(strict_types=1);

namespace Drupal\Tests\workspace_tailwindcss_cli\Unit\Asset;

use Drupal\Tests\UnitTestCase;
use Drupal\workspace_tailwindcss_cli\Asset\TailwindSourceHasher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests Tailwind source detection and hashing.
 */
#[CoversClass(TailwindSourceHasher::class)]
#[Group('workspace_tailwindcss_cli')]
class TailwindSourceHasherTest extends UnitTestCase {

  /**
   * Temporary base directory.
   */
  protected string $baseDir;

  /**
   * Application root within the temporary base directory.
   */
  protected string $appRoot;

  /**
   * Extension root of the test theme.
   */
  protected string $extensionRoot;

  /**
   * The Tailwind entry point, relative to the application root.
   */
  protected string $cssPath = 'themes/custom/test_theme/src/css/main.css';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->baseDir = sys_get_temp_dir() . '/workspace_tailwindcss_cli_' . $this->randomMachineName();
    $this->appRoot = $this->baseDir . '/app';
    $this->extensionRoot = $this->appRoot . '/themes/custom/test_theme';
    foreach (['src/css', 'src/js', 'templates/ignored', 'components/card'] as $directory) {
      mkdir($this->extensionRoot . '/' . $directory, 0777, TRUE);
    }

    file_put_contents($this->extensionRoot . '/test_theme.info.yml', "name: Test theme\n");
    file_put_contents($this->extensionRoot . '/src/css/main.css', implode("\n", [
      '@import "tailwindcss";',
      '@import "./prose.css";',
      '@source "../../templates";',
      '@source not "../../templates/ignored";',
    ]) . "\n");
    file_put_contents($this->extensionRoot . '/src/css/prose.css', ".prose { color: red; }\n");
    file_put_contents($this->extensionRoot . '/src/css/plain.css', "body { color: red; }\n");
    file_put_contents($this->extensionRoot . '/src/css/widget.css', "@reference 'main.css';\n");
    file_put_contents($this->extensionRoot . '/src/js/x.js', "console.log('x');\n");
    file_put_contents($this->extensionRoot . '/templates/a.html.twig', "<div class=\"p-4\"></div>\n");
    file_put_contents($this->extensionRoot . '/templates/ignored/b.html.twig', "<div class=\"p-8\"></div>\n");
    file_put_contents($this->extensionRoot . '/components/card/card.css', "@reference \"../../src/css/main.css\";\n@source \"./**/*.twig\";\n");
    file_put_contents($this->extensionRoot . '/components/card/card.twig', "<div class=\"p-2\"></div>\n");
    file_put_contents($this->extensionRoot . '/components/card/card.js', "console.log('card');\n");
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->removeDirectory($this->baseDir);
    parent::tearDown();
  }

  /**
   * Tests detection and that the hash follows the compiled CSS sources.
   */
  public function testHashFollowsTailwindSources(): void {
    $hasher = new TailwindSourceHasher($this->appRoot);

    $this->assertTrue($hasher->needsCompilation('@import "tailwindcss";'));
    $this->assertTrue($hasher->needsCompilation("@import 'tailwindcss';"));
    $this->assertTrue($hasher->needsCompilation('@reference "tailwindcss";'));
    $this->assertTrue($hasher->needsCompilation('@reference "main.css";'));
    $this->assertTrue($hasher->needsCompilation('/* @tailwindcss-placeholder */'));
    $this->assertFalse($hasher->needsCompilation('body { color: red; }'));
    $this->assertFalse($hasher->needsCompilation('@import "./local.css";'));

    // Plain CSS is not a Tailwind source.
    $this->assertNull($hasher->hash('themes/custom/test_theme/src/css/plain.css'));

    $prose = $this->extensionRoot . '/src/css/prose.css';
    $template = $this->extensionRoot . '/templates/a.html.twig';
    $ignored = $this->extensionRoot . '/templates/ignored/b.html.twig';
    $unrelated = $this->extensionRoot . '/src/js/x.js';

    $base = $this->hashFresh($this->cssPath);
    $this->assertNotNull($base);

    // An imported CSS file is part of the compiled sources.
    file_put_contents($prose, ".prose { color: blue; }\n");
    $after_prose = $this->hashFresh($this->cssPath);
    $this->assertNotSame($base, $after_prose);

    // A file below @source is part of the compiled sources.
    file_put_contents($template, "<div class=\"p-6\"></div>\n");
    $after_template = $this->hashFresh($this->cssPath);
    $this->assertNotSame($after_prose, $after_template);

    // A path excluded with @source not is not part of the sources.
    file_put_contents($ignored, "<div class=\"p-10\"></div>\n");
    $this->assertSame($after_template, $this->hashFresh($this->cssPath));

    // Files outside the @source paths are not part of the sources.
    file_put_contents($unrelated, "console.log('changed');\n");
    $this->assertSame($after_template, $this->hashFresh($this->cssPath));

    // Referenced CSS files resolve their reference relative to themselves.
    $widget_path = 'themes/custom/test_theme/src/css/widget.css';
    $card_path = 'themes/custom/test_theme/components/card/card.css';
    $widget = $this->hashFresh($widget_path);
    $card = $this->hashFresh($card_path);
    $this->assertNotNull($widget);
    $this->assertNotNull($card);

    file_put_contents($this->extensionRoot . '/src/css/main.css', implode("\n", [
      '@import "tailwindcss";',
      '@import "./prose.css";',
      '@source "../../templates";',
      '@source not "../../templates/ignored";',
      '/* changed */',
    ]) . "\n");
    $this->assertNotSame($widget, $this->hashFresh($widget_path));
    $card_after_main = $this->hashFresh($card_path);
    $this->assertNotSame($card, $card_after_main);

    // A glob @source includes only the files it matches.
    file_put_contents($this->extensionRoot . '/components/card/card.js', "console.log('changed');\n");
    $this->assertSame($card_after_main, $this->hashFresh($card_path));
    file_put_contents($this->extensionRoot . '/components/card/card.twig', "<div class=\"p-3\"></div>\n");
    $this->assertNotSame($card_after_main, $this->hashFresh($card_path));
  }

  /**
   * Hashes a path with a fresh hasher, bypassing per-request memoization.
   */
  protected function hashFresh(string $path): ?string {
    return (new TailwindSourceHasher($this->appRoot))->hash($path);
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
