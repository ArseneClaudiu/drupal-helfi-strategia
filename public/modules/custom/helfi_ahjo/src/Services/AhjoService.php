<?php

namespace Drupal\helfi_ahjo\Services;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Messenger\MessengerInterface;
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
   * Messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

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
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   Messenger service.
   */
  public function __construct(
    ModuleExtensionList $extension_list_module,
    TaxonomyUtils $taxonomyUtils,
    EntityTypeManagerInterface $entity_type_manager,
    ClientInterface $guzzleClient,
    MessengerInterface $messenger
  ) {
    $this->moduleExtensionList = $extension_list_module;
    $this->taxonomyUtils = $taxonomyUtils;
    $this->entityTypeManager = $entity_type_manager;
    $this->guzzleClient = $guzzleClient;
    $this->messenger = $messenger;
  }

  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('extension.list.module'),
      $container->get('helfi_ahjo.taxonomy_utils'),
      $container->get('entity_type.manager'),
      $container->get('http_client'),
      $container->get('messenger')
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
      throw new \Exception($e->getResponse()->getStatusCode());
    }

  }

  /**
   * @param array $childData
   * @param array $operations
   * @param int $parentId
   *
   * Recursive set all information from ahjo api.
   *
   * @return array|mixed
   */
  public function setAllBatchOperations($childData = [], &$operations = [], $parentId = 0) {
    foreach ($childData as $content) {
      $content['parentId'] = $parentId;
      $operations[] = [$this->createTaxonomyTermsBatch(), [$content]];

      if (isset($content['OrganizationLevelBelow'])) {
        $this->setAllBatchOperations($content['OrganizationLevelBelow'], $operations, $content['ID']);
      }
    }

    return $operations;
  }

  /**
   * Create batch operations for taxonomy sote_section.
   *
   * @param array $data
   *   Data for batch.
   *
   * @return void
   */
  public function createTaxonomyBatch($data) {
    if (!is_array($data)) {
      $data = Json::decode($data);
    }

    $operations = [];

    $operations[] = $this->setAllBatchOperations($data);

    $batch = [
      'operations' => $operations,
      'finished' => $this->createTaxonomyTermsBatchFinished(),
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
    $this->syncTaxonomyTermsChilds();
  }

  /**
   * {@inheritDoc}
   */
  public function syncTaxonomyTermsChilds() {
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
    $terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadTree('sote_section', $organization, $maxDepth);

    $tree = [];
    foreach ($terms as $tree_object) {
      $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($tree_object->tid);
      if (in_array($term->field_type_id, $excludedByTypeId)) {
        continue;
      }
      $this->taxonomyUtils->buildTree($tree, $tree_object, 'sote_section');
    }

    return $tree;
  }

  public function createTaxonomyTermsBatch($data, &$context) {
    $message = 'Creating taxonomy terms...';

    $loadByExternalId = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties([
      'vid' => 'sote_section',
      'field_external_id' => $data['ID'],
    ]);

    if (count($loadByExternalId) == 0) {
      $term = Term::create([
        'name' => $data['Name'],
        'vid' => 'sote_section',
        'field_external_id' => $data['ID'],
        'field_external_parent_id' => $data['parentId'],
        'field_section_type' => $data['Type'],
        'field_type_id' => $data['TypeId'],
        'parent' => $context['importedTids'][$data['ID']] ?? 0,
      ]);
      $term->save();
    }
    else {
      $term = array_values($loadByExternalId);

      $term[0]->set('field_external_id', $data['ID']);
      $term[0]->set('field_external_parent_id', $data['parentId']);
      $term[0]->set('field_section_type', $data['Type']);
      $term[0]->set('field_type_id', $data['TypeId']);
      $term[0]->set('parent', $context['importedTids'][$data['ID']] ?? 0);
      $term[0]->save();
    }

    $context['importedTids'][$data['ID']] = $term->id();

    $context['message'] = $message;
  }

  public function createTaxonomyTermsBatchFinished($success, $results, $operations) {
    if ($success) {
      $message = t('Terms processed.');

    }
    else {
      $message = t('Finished with an error.');
    }
    $this->syncTaxonomyTermsChilds();
    $this->messenger->addStatus($message);

  }
}
