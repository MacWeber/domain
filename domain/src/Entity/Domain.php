<?php

/**
 * @file
 * Definition of Drupal\domain\Entity\Domain.
 */

namespace Drupal\domain\Entity;

use Drupal\domain\DomainInterface;
use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Config\Entity\ConfigEntityStorage;
use Drupal\Core\Url;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Defines the domain entity.
 *
 * @ConfigEntityType(
 *   id = "domain",
 *   label = @Translation("Domain record"),
 *   module = "domain",
 *   handlers = {
 *     "storage" = "Drupal\Core\Config\Entity\ConfigEntityStorage",
 *     "view_builder" = "Drupal\domain\DomainViewBuilder",
 *     "access" = "Drupal\domain\DomainAccessControlHandler",
 *     "list_builder" = "Drupal\domain\DomainListBuilder",
 *     "view_builder" = "Drupal\domain\DomainViewBuilder",
 *     "form" = {
 *       "default" = "Drupal\domain\DomainForm",
 *       "edit" = "Drupal\domain\DomainForm",
 *       "delete" = "Drupal\domain\Form\DomainDeleteForm"
 *     }
 *   },
 *   config_prefix = "record",
 *   admin_permission = "administer domains",
 *   entity_keys = {
 *     "id" = "id",
 *     "domain_id" = "domain_id",
 *     "label" = "name",
 *     "uuid" = "uuid",
 *     "weight" = "weight"
 *   },
 *   links = {
 *     "delete-form" = "/admin/structure/domain/delete/{domain}",
 *     "edit-form" = "/admin/structure/domain/edit/{domain}"
 *   }
 * )
 */
class Domain extends ConfigEntityBase implements DomainInterface {

  use StringTranslationTrait;

  /**
   * The ID of the domain entity.
   *
   * @var string
   */
  protected $id;

  /**
   * The domain record ID.
   *
   * @var integer
   */
  protected $domain_id;

  /**
   * The domain record UUID.
   *
   * @var string
   */
  protected $uuid;

  /**
   * The domain list name (e.g. Drupal).
   *
   * @var string
   */
  protected $name;

  /**
   * The domain hostname (e.g. example.com).
   *
   * @var string
   */
  protected $hostname;

  /**
   * The domain status.
   *
   * @var boolean
   */
  protected $status;

  /**
   * The domain record sort order.
   *
   * @var integer
   */
  protected $weight;

  /**
   * Indicates the default domain.
   *
   * @var boolean
   */
  protected $is_default;

  /**
   * The domain record protocol (e.g. http://).
   *
   * @var string
   */
  protected $scheme;

  /**
   * The domain record base path, a calculated value.
   *
   * @var string
   */
  protected $path;

  /**
   * The domain record current url, a calculated value.
   *
   * @var string
   */
  protected $url;

  /**
   * The domain record http response test (e.g. 200), a calculated value.
   *
   * @var integer
   */
  protected $response = NULL;

  /**
   * The redirect method to use, if needed.
   */
  protected $redirect = NULL;

  /**
   * {@inheritdoc}
   */
  public static function preCreate(EntityStorageInterface $storage_controller, array &$values) {
    parent::preCreate($storage_controller, $values);
    $loader = \Drupal::service('domain.loader');
    $creator = \Drupal::service('domain.creator');
    $default = $loader->loadDefaultId();
    $domains = $loader->loadMultiple();
    $values += array(
      'scheme' => empty($GLOBALS['is_https']) ? 'http' : 'https',
      'status' => 1,
      'weight' => count($domains) + 1,
      'is_default' => (int) empty($default),
      // {node_access} still requires a numeric id.
      // @TODO: This is not reliable and creates duplicates.
      'domain_id' => $creator->createNextId(),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function isActive() {
    $negotiator = \Drupal::service('domain.negotiator');
    $domain = $negotiator->negotiateActiveDomain();
    if (empty($domain)) {
      return FALSE;
    }
    return ($this->id() == $domain->id());
  }

  /**
   * {@inheritdoc}
   */
  public function addProperty($name, $value) {
    if (!isset($this->{$name})) {
      $this->{$name} = $value;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function isDefault() {
    return (bool) $this->is_default;
  }

  /**
   * {@inheritdoc}
   */
  public function isHttps() {
    return (bool) ($this->getScheme(FALSE) == 'https');
  }

  /**
   * {@inheritdoc}
   */
  public function saveDefault() {
    if (!$this->isDefault()) {
      // Swap the current default.
      if ($default = domain_default()) {
        $default->is_default = 0;
        $default->save();
      }
      // Save the new default.
      $this->is_default = 1;
      $this->save();
    }
    else {
      drupal_set_message($this->t('The selected domain is already the default.'), 'warning');
    }
  }

  /**
   * Overrides Drupal\Core\Config\Entity\ConfigEntityBase::enable().
   */
  public function enable() {
    $this->setStatus(TRUE);
    $this->save();
  }

  /**
   * Overrides Drupal\Core\Config\Entity\ConfigEntityBase::disable().
   */
  public function disable() {
    if (!$this->isDefault()) {
      $this->setStatus(FALSE);
      $this->save();
    }
    else {
      drupal_set_message($this->t('The default domain cannot be disabled.'), 'warning');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function saveProperty($name, $value) {
    if (isset($this->{$name})) {
      $this->{$name} = $value;
      $this->save();
      drupal_set_message($this->t('The @key attribute was set to @value for domain @hostname.', array('@key' => $key, '@value' => $value, '@hostname' => $this->hostname)));
    }
    else {
      drupal_set_message($this->t('The @key attribute does not exist.', array('@key' => $key)));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function setPath() {
    $this->path = $this->getScheme() . $this->hostname . base_path();
  }

  /**
   * {@inheritdoc}
   */
  public function setUrl() {
    $uri = \Drupal::request()->getRequestUri();
    $this->url = $this->getScheme() . $this->hostname . $uri;
  }

  /**
   * {@inheritdoc}
   */
  public function getPath() {
    if (!isset($this->path)) {
      $this->setPath();
    }
    return $this->path;
  }

  /**
   * {@inheritdoc}
   */
  public function getUrl() {
    if (!isset($this->url)) {
      $this->setUrl();
    }
    return $this->url;
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage_controller) {
    // Sets the default domain properly.
    $loader = \Drupal::service('domain.loader');
    $default = $loader->loadDefaultDomain();
    if (!$default) {
      $this->is_default = 1;
    }
    elseif ($this->is_default && $default->id() != $this->id()) {
      // Swap the current default.
      $default->is_default = 0;
      $default->save();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getScheme($add_suffix = TRUE) {
    $scheme = $this->scheme;
    if ($scheme != 'https') {
      $scheme = 'http';
    }
    $scheme .= ($add_suffix) ? '://' : '';

    return $scheme;
  }

  /**
   * {@inheritdoc}
   */
  public function getResponse() {
    if (empty($this->response)) {
      $validator = \Drupal::service('domain.validator');
      $validator->checkResponse($this);
    }
    return $this->response;
  }

  /**
   * {@inheritdoc}
   */
  public function setResponse($response) {
    $this->response = $response;
  }

  /**
   * {@inheritdoc}
   */
  public function getLink($current_path = TRUE) {
    $options = array('absolute' => TRUE, 'https' => $this->isHttps());
    if ($current_path) {
      $url = Url::fromUri($this->getUrl(), $options);
    }
    else {
      $url = Url::fromUri($this->getPath(), $options);
    }
    return \Drupal::l($this->getHostname(), $url);
  }

  /**
   * {@inheritdoc}
   */
  function getRedirect() {
    return $this->redirect;
  }

  /**
   * {@inheritdoc}
   */
  function setRedirect($code = 302) {
    $this->redirect = $code;
  }

  /**
   * {@inheritdoc}
   */
  function getHostname() {
    return $this->hostname;
  }

  /**
   * {@inheritdoc}
   */
  function setHostname($hostname) {
    $this->hostname = $hostname;
  }

  /**
   * {@inheritdoc}
   */
  function getDomainId() {
    return $this->domain_id;
  }

  /**
   * {@inheritdoc}
   */
  function getWeight() {
    return $this->weight;
  }

}
