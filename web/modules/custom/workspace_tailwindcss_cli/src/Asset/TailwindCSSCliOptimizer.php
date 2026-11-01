<?php

declare(strict_types=1);

namespace Drupal\workspace_tailwindcss_cli\Asset;

use Drupal\Core\Asset\CssOptimizer;
use Drupal\Core\Extension\ExtensionPathResolver;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Symfony\Component\Process\Process;

/**
 * Compiles Tailwind CSS sources during CSS aggregation.
 */
class TailwindCSSCliOptimizer extends CssOptimizer {

  /**
   * Constructs the optimizer.
   *
   * @param \Drupal\Core\File\FileUrlGeneratorInterface $file_url_generator
   *   The file URL generator.
   * @param \Drupal\Core\Extension\ExtensionPathResolver $extensionPathResolver
   *   The extension path resolver.
   * @param \Drupal\workspace_tailwindcss_cli\Asset\TailwindSourceHasher $sourceHasher
   *   The Tailwind source hasher.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The module logger channel.
   * @param string $appRoot
   *   The Drupal application root.
   */
  public function __construct(
    FileUrlGeneratorInterface $file_url_generator,
    private readonly ExtensionPathResolver $extensionPathResolver,
    private readonly TailwindSourceHasher $sourceHasher,
    private readonly LoggerChannelInterface $logger,
    private readonly string $appRoot,
  ) {
    parent::__construct($file_url_generator);
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $css_asset
   *   A CSS asset.
   */
  public function optimize(array $css_asset) {
    if ($css_asset['type'] !== 'file') {
      throw new \Exception('Only file CSS assets can be optimized.');
    }
    if (!$css_asset['preprocess']) {
      throw new \Exception('Only file CSS assets with preprocessing enabled can be optimized.');
    }

    return $this->chooseStrategy($css_asset);
  }

  /**
   * Chooses between Tailwind compilation and normal CSS processing.
   *
   * @param array<string, mixed> $css_asset
   *   A CSS asset.
   *
   * @return string
   *   The optimized asset contents.
   */
  protected function chooseStrategy(array $css_asset): string {
    $data = $css_asset['data'] ?? NULL;
    if (!is_string($data)) {
      $this->logger->error('CSS asset has no string data path.');
      return parent::processFile($css_asset);
    }
    $file = $this->appRoot . '/' . $data;
    $contents = @file_get_contents($file);

    if ($contents === FALSE) {
      $this->logger->error('Unable to read CSS asset @file.', ['@file' => $file]);
      return parent::processFile($css_asset);
    }

    return $this->sourceHasher->needsCompilation($contents)
      ? $this->compileFile($css_asset)
      : parent::processFile($css_asset);
  }

  /**
   * Resolves the Tailwind CLI binary.
   *
   * The module's own node_modules is authoritative; the project-root binary is
   * only a fallback for installations that hoist dependencies.
   */
  protected function resolveBinary(): ?string {
    $module_path = $this->extensionPathResolver->getPath('module', 'workspace_tailwindcss_cli');
    $candidates = [
      $this->appRoot . '/' . $module_path . '/node_modules/.bin/tailwindcss',
      $this->appRoot . '/../node_modules/.bin/tailwindcss',
    ];

    foreach ($candidates as $candidate) {
      if (is_file($candidate) && is_executable($candidate)) {
        return realpath($candidate) ?: $candidate;
      }
    }

    return NULL;
  }

  /**
   * Compiles a Tailwind source file with the CLI.
   *
   * @param array<string, mixed> $css_asset
   *   A CSS asset.
   *
   * @return string
   *   The optimized asset contents.
   */
  protected function compileFile(array $css_asset): string {
    $data = $css_asset['data'] ?? NULL;
    if (!is_string($data)) {
      $this->logger->error('CSS asset has no string data path.');
      return parent::processFile($css_asset);
    }

    $binary = $this->resolveBinary();
    if ($binary === NULL) {
      $this->logger->error('Tailwind CLI binary not found. Falling back to the unprocessed CSS source (which may contain @import "tailwindcss").');
      return parent::processFile($css_asset);
    }

    $file = $this->appRoot . '/' . $data;
    $cwd = $this->sourceHasher->findExtensionRoot($file);

    $process = new Process(
      [$binary, '-i', $file, '-m', '--cwd', $cwd],
      $cwd,
      NULL,
      NULL,
      60.0,
    );

    try {
      $process->run();
    }
    catch (\Throwable $e) {
      $this->logger->error('Tailwind CLI could not be executed: @message', [
        '@message' => $e->getMessage(),
      ]);
      return parent::processFile($css_asset);
    }

    if (!$process->isSuccessful()) {
      $output = trim($process->getErrorOutput() . "\n" . $process->getOutput());
      $this->logger->error('Tailwind CLI failed with exit code @code: @output. Falling back to the unprocessed CSS source (which may contain @import "tailwindcss").', [
        '@code' => $process->getExitCode(),
        '@output' => $output,
      ]);
      return parent::processFile($css_asset);
    }

    $contents = $process->getOutput();
    if (trim($contents) === '') {
      $this->logger->error('Tailwind CLI returned empty output for @file. Falling back to the unprocessed CSS source (which may contain @import "tailwindcss").', [
        '@file' => $file,
      ]);
      return parent::processFile($css_asset);
    }

    // Get the parent directory of this file, relative to the Drupal root.
    $last_slash = strrpos($data, '/');
    $css_base_path = $last_slash === FALSE ? '' : substr($data, 0, $last_slash);

    // Store base path.
    $this->rewriteFileURIBasePath = $css_base_path . '/';

    // Anchor all paths in the CSS with its base URL, ignoring external and
    // absolute paths and paths starting with '#'.
    $rewritten = preg_replace_callback('/url\(\s*[\'"]?(?![a-z]+:|\/+|#|%23)([^\'")]+)[\'"]?\s*\)/i', [
      $this,
      'rewriteFileURI',
    ], $contents);

    return $rewritten ?? $contents;
  }

}
