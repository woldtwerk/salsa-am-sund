<?php

declare(strict_types=1);

namespace Drupal\workspace_tailwindcss_cli\Asset;

/**
 * Detects Tailwind sources and hashes the files they are compiled from.
 *
 * The hash covers the entry CSS file, the CSS files it imports or references
 * and the files named by its @source rules.
 */
final class TailwindSourceHasher {

  /**
   * Directories that never contain Tailwind sources.
   */
  private const SKIPPED_DIRECTORIES = ['node_modules', 'vendor'];

  /**
   * Memoized hashes keyed by the CSS path relative to the application root.
   *
   * @var array<string, string|null>
   */
  private array $hashes = [];

  /**
   * Constructs the hasher.
   *
   * @param string $appRoot
   *   The Drupal application root.
   */
  public function __construct(
    private readonly string $appRoot,
  ) {}

  /**
   * Determines whether a CSS source needs the Tailwind CLI.
   *
   * @param string $contents
   *   The CSS file contents.
   */
  public function needsCompilation(string $contents): bool {
    return (bool) preg_match(
      '#@tailwindcss-placeholder|@reference(?:\s|;|$)|@import\s+(?:"|\')tailwindcss#',
      $contents,
    );
  }

  /**
   * Hashes the sources Tailwind compiles a CSS file from.
   *
   * @param string $relativePath
   *   The CSS path relative to the application root.
   *
   * @return string|null
   *   The hash, or NULL when the file cannot be read or is not a Tailwind
   *   source.
   */
  public function hash(string $relativePath): ?string {
    if (array_key_exists($relativePath, $this->hashes)) {
      return $this->hashes[$relativePath];
    }

    $file = $this->appRoot . '/' . $relativePath;
    $contents = is_file($file) ? @file_get_contents($file) : FALSE;
    if ($contents === FALSE || !$this->needsCompilation($contents)) {
      return $this->hashes[$relativePath] = NULL;
    }

    $cssFiles = $this->collectCssGraph($file);
    $sources = [];
    $excluded = [];
    foreach (array_keys($cssFiles) as $cssFile) {
      $cssContents = @file_get_contents($cssFile);
      if ($cssContents === FALSE) {
        continue;
      }
      preg_match_all('/@source\s+(not\s+)?(?:"([^"]+)"|\'([^\']+)\')/', $cssContents, $matches, PREG_SET_ORDER);
      foreach ($matches as $match) {
        $specifier = ($match[2] ?? '') !== '' ? $match[2] : ($match[3] ?? '');
        if ($specifier === '') {
          continue;
        }
        $files = $this->expandSource(dirname($cssFile), $specifier);
        if (trim($match[1] ?? '') !== '') {
          $excluded += $files;
        }
        else {
          $sources += $files;
        }
      }
    }

    $files = $cssFiles + array_diff_key($sources, $excluded);
    ksort($files);

    $hash = hash_init('xxh64');
    foreach (array_keys($files) as $path) {
      $pathRelativeToRoot = $this->relativePath($this->appRoot, $path);
      hash_update($hash, $pathRelativeToRoot . "\0");
      hash_update($hash, (string) @file_get_contents($path) . "\0");
    }

    return $this->hashes[$relativePath] = hash_final($hash);
  }

  /**
   * Finds the extension root for a file inside a theme or module.
   *
   * @param string $file
   *   The absolute path to a file inside the extension.
   */
  public function findExtensionRoot(string $file): string {
    $directory = dirname($file);

    while ($directory !== $this->appRoot && str_starts_with($directory, $this->appRoot)) {
      $info_files = glob($directory . '/*.info.yml');
      if (is_array($info_files) && $info_files !== []) {
        return $directory;
      }
      $parent = dirname($directory);
      if ($parent === $directory) {
        break;
      }
      $directory = $parent;
    }

    return dirname($file);
  }

  /**
   * Collects the CSS files the entry file imports or references.
   *
   * @param string $entry
   *   The absolute path to the entry CSS file.
   *
   * @return array<string, true>
   *   CSS file paths keyed by path, including the entry file.
   */
  private function collectCssGraph(string $entry): array {
    $files = [];
    $stack = [$entry];
    while ($stack !== []) {
      $file = array_pop($stack);
      if (isset($files[$file]) || !is_file($file)) {
        continue;
      }
      $files[$file] = TRUE;

      $contents = @file_get_contents($file);
      if ($contents === FALSE) {
        continue;
      }
      foreach ($this->parseCssDependencies($contents, dirname($file)) as $dependency) {
        if (!isset($files[$dependency])) {
          $stack[] = $dependency;
        }
      }
    }

    return $files;
  }

  /**
   * Parses @import and @reference rules.
   *
   * @param string $contents
   *   The CSS contents.
   * @param string $directory
   *   The directory of the CSS file, used to resolve relative paths.
   *
   * @return list<string>
   *   Absolute paths of existing CSS files.
   */
  private function parseCssDependencies(string $contents, string $directory): array {
    $dependencies = [];
    preg_match_all('/@import\s+(?:"([^"]+)"|\'([^\']+)\')[^;]*;|@reference\s+(?:"([^"]+)"|\'([^\']+)\')/', $contents, $matches, PREG_SET_ORDER);
    foreach ($matches as $match) {
      $specifier = '';
      foreach ([1, 2, 3, 4] as $index) {
        if (($match[$index] ?? '') !== '') {
          $specifier = $match[$index];
          break;
        }
      }
      if ($specifier === '') {
        continue;
      }
      $path = $this->resolvePath($directory, $specifier);
      if ($path !== '' && is_file($path)) {
        $dependencies[$path] = TRUE;
      }
    }

    return array_keys($dependencies);
  }

  /**
   * Expands one @source rule to the files it designates.
   *
   * @param string $directory
   *   The directory of the CSS file containing the rule.
   * @param string $specifier
   *   The path, directory or glob from the @source rule.
   *
   * @return array<string, true>
   *   File paths keyed by path.
   */
  private function expandSource(string $directory, string $specifier): array {
    if (strpbrk($specifier, '*?{') !== FALSE) {
      return $this->expandGlob($directory, $specifier);
    }

    $target = $this->resolvePath($directory, $specifier);
    if ($target === '') {
      return [];
    }
    if (is_file($target)) {
      return [$target => TRUE];
    }
    if (is_dir($target)) {
      return $this->collectFiles($target);
    }

    return [];
  }

  /**
   * Expands a glob @source rule.
   *
   * @param string $directory
   *   The directory of the CSS file containing the rule.
   * @param string $glob
   *   The glob from the @source rule.
   *
   * @return array<string, true>
   *   File paths keyed by path.
   */
  private function expandGlob(string $directory, string $glob): array {
    // Matched against paths relative to $directory, which never start with
    // './'.
    while (str_starts_with($glob, './')) {
      $glob = substr($glob, 2);
    }

    $base_parts = [];
    foreach (explode('/', $glob) as $part) {
      if (strpbrk($part, '*?{') !== FALSE) {
        break;
      }
      $base_parts[] = $part;
    }

    $base = $base_parts === [] ? $directory : $this->resolvePath($directory, implode('/', $base_parts));
    if ($base === '' || !is_dir($base)) {
      return [];
    }

    $regex = $this->globToRegex($glob);
    $files = [];
    foreach (array_keys($this->collectFiles($base)) as $path) {
      if (preg_match($regex, $this->relativePath($directory, $path)) === 1) {
        $files[$path] = TRUE;
      }
    }

    return $files;
  }

  /**
   * Converts a glob to a regular expression.
   *
   * @param string $glob
   *   The glob pattern.
   */
  private function globToRegex(string $glob): string {
    $regex = '';
    $length = strlen($glob);
    for ($i = 0; $i < $length; $i++) {
      $char = $glob[$i];
      if ($char === '*') {
        if (($glob[$i + 1] ?? '') === '*') {
          $i++;
          if (($glob[$i + 1] ?? '') === '/') {
            $i++;
            $regex .= '(?:.*/)?';
          }
          else {
            $regex .= '.*';
          }
        }
        else {
          $regex .= '[^/]*';
        }
      }
      elseif ($char === '?') {
        $regex .= '[^/]';
      }
      elseif ($char === '{') {
        $end = strpos($glob, '}', $i);
        if ($end === FALSE) {
          $regex .= preg_quote($char, '#');
          continue;
        }
        $alternatives = explode(',', substr($glob, $i + 1, $end - $i - 1));
        $regex .= '(?:' . implode('|', array_map(
          static fn (string $alternative): string => preg_quote($alternative, '#'),
          $alternatives,
        )) . ')';
        $i = $end;
      }
      else {
        $regex .= preg_quote($char, '#');
      }
    }

    return '#^' . $regex . '$#';
  }

  /**
   * Collects all files below a directory.
   *
   * @param string $directory
   *   The absolute path to the directory.
   *
   * @return array<string, true>
   *   File paths keyed by path.
   */
  private function collectFiles(string $directory): array {
    $iterator = new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS);
    $filter = new \RecursiveCallbackFilterIterator($iterator, static function (\SplFileInfo $current): bool {
      if (!$current->isDir()) {
        return TRUE;
      }
      $name = $current->getFilename();
      return $name[0] !== '.' && !in_array($name, self::SKIPPED_DIRECTORIES, TRUE);
    });

    $files = [];
    foreach (new \RecursiveIteratorIterator($filter) as $file) {
      if ($file instanceof \SplFileInfo && $file->isFile()) {
        $files[$file->getPathname()] = TRUE;
      }
    }

    return $files;
  }

  /**
   * Resolves a path relative to a directory.
   *
   * @param string $directory
   *   The absolute base directory.
   * @param string $path
   *   The relative path.
   *
   * @return string
   *   The normalized absolute path, or an empty string when the path is not
   *   relative.
   */
  private function resolvePath(string $directory, string $path): string {
    $path = trim($path);
    if ($path === '' || str_starts_with($path, '/') || preg_match('#^[a-z][a-z0-9+.-]*:#i', $path)) {
      return '';
    }

    $parts = [];
    foreach (explode('/', str_replace('\\', '/', $directory . '/' . $path)) as $part) {
      if ($part === '' || $part === '.') {
        continue;
      }
      if ($part === '..') {
        array_pop($parts);
        continue;
      }
      $parts[] = $part;
    }

    return '/' . implode('/', $parts);
  }

  /**
   * Builds a relative path between two absolute paths.
   *
   * @param string $from
   *   The absolute base path.
   * @param string $to
   *   The absolute target path.
   */
  private function relativePath(string $from, string $to): string {
    $from_parts = array_values(array_filter(explode('/', $from), static fn (string $part): bool => $part !== ''));
    $to_parts = array_values(array_filter(explode('/', $to), static fn (string $part): bool => $part !== ''));

    $common = 0;
    $max = min(count($from_parts), count($to_parts));
    while ($common < $max && $from_parts[$common] === $to_parts[$common]) {
      $common++;
    }

    return str_repeat('../', count($from_parts) - $common) . implode('/', array_slice($to_parts, $common));
  }

}
