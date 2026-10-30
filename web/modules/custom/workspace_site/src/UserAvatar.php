<?php

declare(strict_types=1);

namespace Drupal\workspace_site;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\file\FileInterface;
use Drupal\user\UserInterface;

/**
 * Resolves the initials and profile picture of a user.
 *
 * The theme and the user menu both show the same avatar, so the resolution
 * lives here instead of being duplicated in a preprocess hook and a template.
 */
final class UserAvatar {

  /**
   * Constructs the user avatar resolver.
   *
   * @param \Drupal\Core\File\FileUrlGeneratorInterface $fileUrlGenerator
   *   The file URL generator.
   */
  public function __construct(
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
  ) {}

  /**
   * Returns the avatar data of a user.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user.
   *
   * @return array{initials: string, picture: array{src: string, alt: string, width: int, height: int}|array{}}
   *   The uppercase initials and the picture, or an empty array when the user
   *   has no profile picture.
   */
  public function get(UserInterface $user): array {
    $name = (string) $user->getDisplayName();
    return [
      'initials' => $this->getInitials($name),
      'picture' => $this->getPicture($user, $name),
    ];
  }

  /**
   * Returns the cacheability of the avatar data.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user.
   *
   * @return \Drupal\Core\Cache\CacheableMetadata
   *   The user entity and the profile picture file as dependencies.
   */
  public function getCacheability(UserInterface $user): CacheableMetadata {
    $cache = new CacheableMetadata();
    $cache->addCacheableDependency($user);

    $file = $this->getPictureFile($user);
    if ($file !== NULL) {
      $cache->addCacheableDependency($file);
    }

    return $cache;
  }

  /**
   * Builds the initials from a display name.
   *
   * @param string $name
   *   The display name.
   *
   * @return string
   *   At most two uppercase initials, or an empty string for an empty name.
   */
  public function getInitials(string $name): string {
    $words = preg_split('/\s+/', trim($name)) ?: [];
    $initials = '';
    foreach ($words as $word) {
      if ($word !== '') {
        $initials .= mb_substr($word, 0, 1);
      }
    }
    return mb_strtoupper(mb_substr($initials, 0, 2));
  }

  /**
   * Builds the picture data of a user.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user.
   * @param string $name
   *   The display name, used as alternative text.
   *
   * @return array{src: string, alt: string, width: int, height: int}|array{}
   *   The picture data, or an empty array when there is no picture.
   */
  private function getPicture(UserInterface $user, string $name): array {
    $file = $this->getPictureFile($user);
    if ($file === NULL) {
      return [];
    }

    $uri = $file->getFileUri();
    if ($uri === NULL) {
      return [];
    }

    // Files without an image field (for example an avatar uploaded before the
    // image dimensions were stored) have no width/height values.
    $width = $file->hasField('width') ? ($file->get('width')->value ?? 0) : 0;
    $height = $file->hasField('height') ? ($file->get('height')->value ?? 0) : 0;

    return [
      'src' => $this->fileUrlGenerator->generateString($uri),
      'alt' => $name,
      'width' => is_numeric($width) ? (int) $width : 0,
      'height' => is_numeric($height) ? (int) $height : 0,
    ];
  }

  /**
   * Returns the profile picture file of a user.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user.
   *
   * @return \Drupal\file\FileInterface|null
   *   The file, or NULL when the user has no profile picture.
   */
  private function getPictureFile(UserInterface $user): ?FileInterface {
    if (!$user->hasField('user_picture') || $user->get('user_picture')->isEmpty()) {
      return NULL;
    }

    $file = $user->get('user_picture')->entity;
    return $file instanceof FileInterface ? $file : NULL;
  }

}
