<?php

namespace Drupal\helfi_ahjo\Plugin\QueueWorker;

use Drupal\Core\Queue\QueueWorkerBase;

/**
 * A worker that updates metadata for every image.
 *
 * @QueueWorker(
 *   id = "sote_section_update",
 *   title = @Translation("SOTE Section Update"),
 *   cron = {"time" = 90}
 * )
 */
class SectionUpdate extends QueueWorkerBase {

  /**
   * {@inheritDoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }


  /**
   * {@inheritDoc}
   */
  public function processItem($data) {
    \Drupal::service('helfi_ahjo.ahjo_service')->createTaxonomyBatch($data);
  }
}
