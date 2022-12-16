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
  public function fetchDataFromRemote($orgId = 00001, $maxDepth = 9999): array {
    $config = self::getConfig();
    if (strlen($orgId) < 5) {
      $orgId = sprintf('%05d', $orgId);
    }

    if (strlen($maxDepth) < 4) {
      $maxDepth = sprintf('%04d', $maxDepth);
    }

    $url = sprintf("%s/fi/ahjo-proxy/org-chart/$orgId/$maxDepth?api-key=%s", $config->get('base_url'), $config->get('api_key'));

    $response = $this->guzzleClient->request('GET', $url);
    return Json::decode($response->getBody()->getContents());
  }

  /**
   * @param array $childData
   * @param array $operations
   * @param int $externalParentId
   *
   * Recursive set all information from ahjo api.
   *
   */
  public function setAllBatchOperations($childData = [], &$operations = [], $externalParentId = 0): void {
    foreach ($childData as $content) {
      $content['externalParentId'] = $externalParentId;
      $operations[] = ['_helfi_ahjo_batch_dispatcher',
        [
          'helfi_ahjo.ahjo_service:syncTaxonomyTermsOperation',
          $content,
        ],
      ];

      if (isset($content['OrganizationLevelBelow'])) {
        $this->setAllBatchOperations($content['OrganizationLevelBelow'], $operations, $content['ID']);
      }
    }

  }

  /**
   * Create batch operations for taxonomy sote_section.
   *
   * @param array $data
   *   Data for batch.
   *
   * @return void
   */
  public function createTaxonomyBatch(array $data) {
    $operations = [];

    $this->setAllBatchOperations($data, $operations);

    $batch = [
      'operations' => $operations,
      'finished' => [AhjoService::class, 'syncTermsBatchFinished'],
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
      $section['externalParentId'] = $parentId ?? 0;
      $queue->createItem($section);

      if (isset($section['OrganizationLevelBelow'])) {
        $this->addToCron($section['OrganizationLevelBelow'], $queue, $section['ID']);
      }
    }
  }

  /**
   * {@inheritDoc}
   */
  public function syncTaxonomyTermsChilds() {
//    $terms = $this->entityTypeManager
//      ->getStorage('taxonomy_term')
//      ->loadByProperties(['vid' => 'sote_section']);
//    foreach ($terms as $item) {
//      if (!isset($item->field_external_parent_id->value)
//        || $item->field_external_parent_id->value == NULL
//        || $item->field_external_parent_id->value == '0') {
//        continue;
//      }
//      $loadByExternalId = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties([
//        'vid' => 'sote_section',
//        'field_external_id' => $item->field_external_parent_id->value,
//      ]);
//
//      $item->set('parent', reset($loadByExternalId)->tid->value);
//      $item->save();
//    }
  }

  /**
   * {@inheritDoc}
   */
  public function showDataAsTree($excludedByTypeId = [], $organization = 0, $maxDepth = 0) {
    $terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadTree('sote_section', $organization, $maxDepth);

    $tree = [];
    foreach ($terms as $tree_object) {
      $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($tree_object->tid);
      $typeId = $term->get('field_type_id')->value ?? NULL;
      if ($typeId && in_array($typeId, $excludedByTypeId)) {
        continue;
      }
      $this->taxonomyUtils->buildTree($tree, $tree_object, 'sote_section');
    }

    return $tree;
  }

  public function syncTaxonomyTermsOperation($data, &$context) {
    if (!isset($context['results'][$data['ID']])) {
      $context['results'][$data['ID']] = NULL;
    }
    $message = 'Creating taxonomy terms...';

    $loadByExternalId = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadByProperties([
      'vid' => 'sote_section',
      'field_external_id' => $data['ID'],
    ]);

    if (count($loadByExternalId) == 0) {
      $term = Term::create([
        'name' => $data['Name'],
        'vid' => 'sote_section',
      ]);

    }
    else {
      $term = current($loadByExternalId);
    }

    $term->set('field_external_id', $data['ID']);
    $term->set('field_external_parent_id', $data['parentId']);
    $term->set('field_section_type', $data['Type']);
    $term->set('field_type_id', $data['TypeId']);
    $term->set('parent', $context['results'][$data['externalParentId']] ?? NULL);
    $term->save();

    $context['results'][$data['ID']] = $term->id();

    $context['message'] = $message;
  }

  public static function syncTermsBatchFinished($success, $results, $operations) {
    \Drupal::service('helfi_ahjo.ahjo_service')->doSyncTermsBatchFinished($success, $results, $operations);
  }

  public function doSyncTermsBatchFinished($success, $results, $operations) {
    if ($success) {
      $message = t('Terms processed.');
    }
    else {
      $message = t('Finished with an error.');
    }
    //    $this->syncTaxonomyTermsChilds();
    $this->messenger->addStatus($message);
  }
}
