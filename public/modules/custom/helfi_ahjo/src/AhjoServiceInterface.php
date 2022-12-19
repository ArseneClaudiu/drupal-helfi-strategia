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
   * @param int $orgId
   *   Organisation id if its needed.
   * @param int $maxDepth
   *   Max depth.
   */
  public function fetchDataFromRemote($orgId, $maxDepth);

  /**
   * Create batch operations for taxonomy sote_section.
   *
   * @param array $data
   *   Data for batch.
   */
  public function createTaxonomyBatch(array $data): void;

  /**
   * Recursive set all information from ahjo api.
   *
   * @param array $childData
   *   Child data param.
   * @param array $operations
   *   Operantions param.
   * @param int $externalParentId
   *   External parent id param.
   */
  public function setAllBatchOperations(array $childData, array &$operations, int $externalParentId): void;

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
