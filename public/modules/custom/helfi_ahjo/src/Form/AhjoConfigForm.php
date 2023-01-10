<?php

namespace Drupal\helfi_ahjo\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\helfi_ahjo\Services\AhjoService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Gredi DAM module configuration form.
 */
class AhjoConfigForm extends ConfigFormBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\helfi_ahjo\Services\AhjoService
   */
  protected $ahjoService;

  /**
   * Messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * Logger factory service.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\helfi_ahjo\Services\AhjoService $ahjoService
   *   Services for Ahjo API.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   Service for messenger.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   Service for logger factory.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    AhjoService $ahjoService,
    MessengerInterface $messenger,
    LoggerChannelFactoryInterface $loggerFactory) {
    parent::__construct($config_factory);
    $this->ahjoService = $ahjoService;
    $this->messenger = $messenger;
    $this->loggerFactory = $loggerFactory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('helfi_ahjo.ahjo_service'),
      $container->get('messenger'),
      $container->get('logger.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'helfi_ahjo.config',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'gredi_ahjo_config';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('helfi_ahjo.config');

    $form['api_config'] = [
      '#type' => 'fieldset',
      '#title' => $this
        ->t('API Configs'),
    ];
    $form['api_config']['helfi_ahjo_base_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Ahjo Base URL'),
      '#default_value' => $config->get('base_url'),
      '#description' => $this->t('example: demo.ahjo.fi'),
      '#required' => TRUE,
    ];

    $form['api_config']['helfi_ahjo_api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Ahjo API Key'),
      '#default_value' => $config->get('api_key'),
      '#description' => $this->t('apikey'),
      '#required' => TRUE,
    ];

    $form['cron_config'] = [
      '#type' => 'fieldset',
      '#title' => $this
        ->t('Cron Configs'),
    ];
    $form['cron_config']['sync_interval'] = [
      '#type' => 'select',
      '#title' => $this->t('Ahjo Sections Update Interval'),
      '#options' => [
        '-1' => $this->t('Every cron run'),
        '3600' => $this->t('Every hour'),
        '7200' => $this->t('Every 2 hours'),
        '10800' => $this->t('Every 3 hours'),
        '14400' => $this->t('Every 4 hours'),
        '21600' => $this->t('Every 6 hours'),
        '28800' => $this->t('Every 8 hours'),
        '43200' => $this->t('Every 12 hours'),
        '86400' => $this->t('Every 24 hours'),
      ],
      '#default_value' => empty($config->get('sync_interval')) ? 86400 : $config->get('sync_interval'),
      '#description' => $this->t('How often should Ahjo Sections be synced with AhjoProxy?'),
      '#required' => TRUE,
    ];

    $form['cron_config']['org_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Organisation ID (Start)'),
      '#default_value' => $config->get('org_id') ?? 00001,
      '#description' => $this->t('example: 00001'),
      '#required' => TRUE,
    ];

    $form['cron_config']['max_depth'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Max Depth'),
      '#default_value' => $config->get('max_depth') ?? 9999,
      '#description' => $this->t('example: 9999'),
      '#required' => TRUE,
    ];

    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save Ahjo Configuration'),
      '#button_type' => 'primary',
    ];

    $form['cron_config']['actions']['import_sync'] = [
      '#type' => 'submit',
      '#value' => $this->t('Sync now'),
      '#button_type' => 'primary',
      '#submit' => ['::importSyncData'],
    ];

    $form['actions']['deleteAllData'] = [
      '#type' => 'submit',
      '#value' => $this->t('Delete all imported data'),
      '#button_type' => 'primary',
      '#submit' => ['::deleteAllData'],
    ];

    return $form;
  }

  /**
   * Validate that the provided values are valid or nor.
   *
   * @param array $form
   *   Form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state instance.
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if (!$form_state->getValue('helfi_ahjo_base_url')) {
      $form_state->setErrorByName(
        'helfi_ahjo_base_url',
        $this->t('Provided base url is not valid.')
      );
      return;
    }

    if (!$form_state->getValue('helfi_ahjo_api_key')) {
      $form_state->setErrorByName(
        'helfi_ahjo_api_key',
        $this->t('Provided api key is not valid.')
      );
    }

    $org_id = $form_state->getValue('org_id');
    if (!$org_id || is_int($org_id)) {
      $form_state->setErrorByName(
        'org_id',
        $this->t('Provided max depth is not valid.')
      );
    }

    $max_depth = $form_state->getValue('max_depth');
    if (!$max_depth || is_int($max_depth)) {
      $form_state->setErrorByName(
        'max_depth',
        $this->t('Provided max depth is not valid.')
      );
    }

  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('helfi_ahjo.config')
      ->set('base_url', $form_state->getValue('helfi_ahjo_base_url'))
      ->set('api_key', $form_state->getValue('helfi_ahjo_api_key'))
      ->set('organigram_max_depth', $form_state->getValue('organigram_max_depth'))
      ->set('sync_interval', $form_state->getValue('sync_interval'))
      ->set('org_id', $form_state->getValue('org_id'))
      ->set('max_depth', $form_state->getValue('max_depth'))

      ->save();
    $this->messenger->addStatus('Settings are updated!');

  }

  /**
   * Import data and sync it.
   */
  public function importSyncData(array &$form, FormStateInterface $form_state) {
    try {
      $data = $this->ahjoService->fetchDataFromRemote($form_state->getValue('org_id'), $form_state->getValue('max_depth'));
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Unable to fetch data from remote'));
      $form_state->setRebuild(TRUE);
    }

    $this->ahjoService->createTaxonomyBatch($data);
    $this->messenger->addStatus('Sections imported and synchronized!');
  }

  /**
   * Delete all imported data from sote section taxonomy.
   */
  public function deleteAllData(array &$form, FormStateInterface $form_state) {
    $terms = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->loadByProperties(['vid' => 'sote_section']);
    $operations = [];
    foreach ($terms as $item) {
      $operations[] = [
        '\Drupal\helfi_ahjo\Services\AhjoService::deleteTaxonomyTermsOperation', [$item],
      ];
    }

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

}
