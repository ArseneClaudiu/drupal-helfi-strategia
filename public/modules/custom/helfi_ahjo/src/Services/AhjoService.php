<?php

namespace Drupal\helfi_ahjo\Services;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\helfi_ahjo\AhjoServiceInterface;
use Drupal\helfi_ahjo\Utils\TaxonomyUtils;
use Drupal\taxonomy\Entity\Term;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class AHJO Service.
 *
 * Factory class for Client.
 */
class AhjoService implements ContainerInjectionInterface, AhjoServiceInterface {

  /**
   * The module extension list.
   *
   * @var \Drupal\Core\Extension\ModuleExtensionList
   */
  protected $moduleExtensionList;

  /**
   * Taxonomy utils.
   *
   * @var \Drupal\helfi_ahjo\Utils\TaxonomyUtils
   */
  protected $taxonomyUtils;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * A fully-configured Guzzle client to pass to the dam client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $guzzleClient;

  /**
   * AHJO Service constructor.
   *
   * @param \Drupal\Core\Extension\ModuleExtensionList $extension_list_module
   *   The module extension list.
   * @param \Drupal\helfi_ahjo\Utils\TaxonomyUtils $taxonomyUtils
   *   Taxonomy utils for tree.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \GuzzleHttp\ClientInterface $guzzleClient
   *   A fully configured Guzzle client to pass to the dam client.
   */
  public function __construct(
    ModuleExtensionList $extension_list_module,
    TaxonomyUtils $taxonomyUtils,
    EntityTypeManagerInterface $entity_type_manager,
    ClientInterface $guzzleClient
  ) {
    $this->moduleExtensionList = $extension_list_module;
    $this->taxonomyUtils = $taxonomyUtils;
    $this->entityTypeManager = $entity_type_manager;
    $this->guzzleClient = $guzzleClient;
  }

  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('extension.list.module'),
      $container->get('helfi_ahjo.taxonomy_utils'),
      $container->get('entity_type.manager'),
      $container->get('http_client')
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function getConfig(): ImmutableConfig {
    return \Drupal::config('helfi_ahjo.config');
  }

  /**
   * {@inheritDoc}
   */
  public function fetchDataFromRemote($orgId = 00001, $maxDepth = 9999): string {
    $config = self::getConfig();
    if (strlen($orgId) < 5) {
      $orgId = sprintf('%05d', $orgId);
    }

    if (strlen($maxDepth) < 4) {
      $maxDepth = sprintf('%04d', $maxDepth);
    }

    try {
      $url = sprintf("%s/fi/ahjo-proxy/org-chart/$orgId/$maxDepth?api-key=%s", $config->get('base_url'), $config->get('api_key'));

      $response = $this->guzzleClient->request('GET', $url);

      return $response->getBody()->getContents();
    }
    catch (ClientException $e) {
      $statusCode = $e->getResponse()->getStatusCode();
      if ($statusCode === 401) {
        throw new \Exception($statusCode);
      }
    }

  }

  /**
   * Call createTaxonomyTermsTree() and syncTaxonomyTree functions.
   */
  public function insertSyncData($orgId = 00001, $maxDepth = 9999) {
    $this->createTaxonomyTermsBatch($this->fetchDataFromRemote($orgId, $maxDepth));
    $this->syncTaxonomyTermsTree();
  }


  /**
   * @param array $data
   * @param array $append
   * @param int $parentId
   *
   * Recursive set all information from ahjo api.
   *
   * @return array|mixed
   */
  public function setAllInformations($data = [], &$append = [], $parentId = 0) {
    foreach ($data as $content) {
      $content['parentId'] = $parentId;
      $append[] = $content;

      if (isset($content['OrganizationLevelBelow'])) {
        $this->setAllInformations($content['OrganizationLevelBelow'], $append, $content['ID']);
      }
    }

    return $append;
  }

  public function createTaxonomyTermsBatch($data, array &$hierarchy = [], $parentId = 0) {
    if (!is_array($data)) {
      $data = Json::decode($data);
    }

    $operations = [];

    foreach ($this->setAllInformations($data) as $content) {
      $operations[] = ['create_tax_terms_batch', [$content]];
    }
    $batch = [
      'operations' => $operations,
      'finished' => 'create_tax_terms_batch_finished',
      'title' => 'Performing an operation',
      'init_message' => 'Please wait',
      'progress_message' => 'Completed @current from @total',
      'error_message' => 'An error occurred',
    ];

    batch_set($batch);

  }

  /**
   * {@inheritDoc}
   */
  public function addToCron($data, $queue, $parentId = NULL) {
    if (!is_array($data)) {
      $data = Json::decode($data);
    }

    foreach ($data as $section) {
      $section['parentId'] = $parentId ?? 0;
      $queue->createItem($section);

      if (isset($section['OrganizationLevelBelow'])) {
        $this->addToCron($section['OrganizationLevelBelow'], $queue, $section['ID']);
      }
    }
    $this->syncTaxonomyTermsTree();
  }

  /**
   * {@inheritDoc}
   */
  public function syncTaxonomyTermsTree() {
    $terms = $this->entityTypeManager
      ->getStorage('taxonomy_term')
      ->loadByProperties(['vid' => 'sote_section']);
    foreach ($terms as $item) {
      if (!isset($item->field_external_parent_id->value)
        || $item->field_external_parent_id->value == NULL
        || $item->field_external_parent_id->value == '0') {
        continue;
      }
      $loadByExternalId = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties([
        'vid' => 'sote_section',
        'field_external_id' => $item->field_external_parent_id->value,
      ]);

      $item->set('parent', reset($loadByExternalId)->tid->value);
      $item->save();

    }
  }

  /**
   * {@inheritDoc}
   */
  public function showDataAsTree($excludedByTypeId = [], $organization = 0, $maxDepth = 0) {
    return $this->taxonomyUtils->load('sote_section', $excludedByTypeId, $organization, $maxDepth);
  }

}
