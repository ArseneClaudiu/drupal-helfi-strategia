<?php

namespace Drupal\helfi_ahjo;

use Drupal\Core\Config\ImmutableConfig;

/**
 * Ahjo Service Interface.
 */
interface AhjoServiceInterface {

  /**
   * Return the Ahjo API configs.
   *
   * @return \Drupal\Core\Config\ImmutableConfig
   *   An immutable configuration object.
   */
  public static function getConfig(): ImmutableConfig;

  /**
   * Get data from api and add it as taxonomy terms tree.
   *
   * @param string $orgId
   *   Organisation id if its needed.
   * @param int $maxDepth
   *   Max depth
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function fetchDataFromRemote($orgId, $maxDepth);

  /**
   * Add to cron queue.
   *
   * @param array $data
   *   Data fetched from api.
   * @param object $queue
   *   Queue object.
   * @param int|null $parentId
   *   Parent id if it exists.
   */
  public function addToCron(array $data, object $queue, int $parentId = NULL);

  /**
   * Sync sote section taxonomy tree.
   */
  public function syncTaxonomyTermsChilds();

  /**
   * Create taxonomy tree.
   *
   * @param array|null $excludedByTypeId
   *   Exclude by type id.
   *
   * @return array
   *   Return taxonomy tree.
   */
  public function showDataAsTree($excludedByTypeId);

}
