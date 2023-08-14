<?php

namespace Drupal\who_memberships;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Messenger\MessengerInterface;
use GuzzleHttp\ClientInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Mail\MailManagerInterface;
use GuzzleHttp\Exception\RequestException;

/**
 * WHO mwembership manager.
 */
class WhoMembershipManager {

  /**
   * An http client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * Private storage.
   *
   * @var \Drupal\Core\TempStore\PrivateTempStoreFactory
   */
  protected $privateTempStore;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $account;

  /**
   * The mail manager service.
   *
   * @var \Drupal\Core\Mail\MailManagerInterface
   */
  protected $mailManager;

  /**
   * The database connection object.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $connection;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * Create a KeyCloakHttpClient object.
   *
   * @param \GuzzleHttp\ClientInterface $http_client
   *   An HTTP client.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $tempstore
   *   The private storage.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration object factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The current user.
   * @param \Drupal\Core\Mail\MailManagerInterface $mail_manager
   *   The mail manager service.
   * @param \Drupal\Core\Database\Connection $connection
   *   The database connection object.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   */
  public function __construct(ClientInterface $http_client, LoggerChannelFactoryInterface $logger_factory, PrivateTempStoreFactory $tempstore, ConfigFactoryInterface $config_factory, EntityTypeManagerInterface $entity_type_manager, AccountInterface $account, MailManagerInterface $mail_manager, Connection $connection, MessengerInterface $messenger) {
    $this->httpClient = $http_client;
    $this->loggerFactory = $logger_factory;
    $this->privateTempStore = $tempstore;
    $this->configFactory = $config_factory;
    $this->systemConfig = $config_factory->get('system.site');
    $this->entityTypeManager = $entity_type_manager;
    $this->account = $account;
    $this->mailManager = $mail_manager;
    $this->connection = $connection;
    $this->messenger = $messenger;
  }

  /**
   * Service method to create a membership for a product.
   *
   *  Create a Membership.
   */
  public function createMembership(object $entity, $operation = NULL):bool {
    $node_storage = $this->entityTypeManager->getStorage($entity->getEntityTypeId());
    if ($entity->get('field_memberships')->isEmpty()) {
      return FALSE;
    }
    $get_members = $entity->get('field_memberships')->getValue();
    foreach ($get_members as $key => $value) {
      $account = $this->getUserInfo($value['target_id']);
      $get_contact = !$account->get('field_contact_id')->isEmpty() ? $node_storage->load($account->get('field_contact_id')->target_id) : NULL;
      if ($operation === 'update_new_member') {
        $query = $this->entityTypeManager->getStorage($entity->getEntityTypeId());
        $query_result = $query->getQuery()
          ->accessCheck(FALSE)
          ->condition('type', 'subscription')
          ->condition('title', "{$entity->getTitle()} {$get_contact->get('field_firstname')->value} {$get_contact->get('field_lastname')->value} {$node_storage->load($get_contact->get('field_company')->target_id)->getTitle()}", "=");
        $membership_exist = $query_result->execute();
        if (!empty($membership_exist)) {
          continue;
        }
        $this->messenger->addStatus(t('Membership updated for: @product', [
          '@product' => $entity->getTitle(),
        ]));
      }
      try {
        $info = [
          'type' => 'subscription',
          'title' => $entity->getTitle() ? "{$entity->getTitle()} {$get_contact->get('field_firstname')->value} {$get_contact->get('field_lastname')->value} {$node_storage->load($get_contact->get('field_company')->target_id)->getTitle()}" : NULL,
          'field_product_id_ref' => $entity->id(),
          'field_account_id' => $account->id(),
          'field_subscription_status' => 1,
          'field_termination_date' => NULL,
        ];
        $membership = $this->entityTypeManager->getStorage($entity->getEntityTypeId())->create($info);
        $membership->save();
        if ($membership && is_null($operation)) {
          $this->messenger->addStatus(t('Membership created for: @product', [
            '@product' => $entity->getTitle(),
          ]));
        }
      }
      catch (RequestException $e) {
        $this->loggerFactory->get('who_memberships')->error($e->getMessage());
      }
    }
    return TRUE;
  }

  /**
   * Get user infor.
   *
   * @return object
   *   User entity.
   */
  public function getUserInfo(int $uid) {
    if (empty($uid)) {
      return [];
    }
    return $this->entityTypeManager->getStorage('user')->load($uid);
  }

}
