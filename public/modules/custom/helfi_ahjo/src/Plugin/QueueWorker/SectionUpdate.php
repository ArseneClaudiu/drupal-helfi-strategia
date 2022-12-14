<?php

namespace Drupal\helfi_ahjo\Plugin\QueueWorker;

use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\helfi_ahjo\Services\AhjoService;

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
   * The entity type manager.
   *
   * @var \Drupal\helfi_ahjo\Services\AhjoService
   */
  protected $ahjoService;

  /**
   * {@inheritDoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, AhjoService $ahjoService) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->ahjoService = $ahjoService;
  }

  /**
   * {@inheritDoc}
   */
  public function processItem($data) {
    $this->ahjoService->createTaxonomyBatch($data);
  }
}
