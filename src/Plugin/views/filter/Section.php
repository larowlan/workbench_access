<?php

namespace Drupal\workbench_access\Plugin\views\filter;

use Drupal\Core\Form\FormStateInterface;
use Drupal\views\Plugin\views\filter\ManyToOne;
use Drupal\views\Plugin\views\display\DisplayPluginBase;
use Drupal\views\Views;
use Drupal\views\ViewExecutable;
use Drupal\views\ManyToOneHelper;
use Drupal\workbench_access\Entity\AccessSchemeInterface;
use Drupal\workbench_access\WorkbenchAccessManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Filter by assigned section.
 *
 * @ingroup views_filter_handlers
 *
 * @ViewsFilter("workbench_access_section")
 */
class Section extends ManyToOne {

  /**
   * Scheme.
   *
   * @var \Drupal\workbench_access\Entity\AccessSchemeInterface
   */
  protected $scheme;

  /**
   * Manager.
   *
   * @var \Drupal\workbench_access\WorkbenchAccessManagerInterface
   */
  protected $manager;

  /**
   * User storage.
   *
   * @var \Drupal\workbench_access\UserSectionStorageInterface
   */
  protected $userSectionStorage;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    /** @var self $instance */
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    return $instance
      ->setScheme($container->get('entity_type.manager')->getStorage('access_scheme')->load($configuration['scheme']))
      ->setManager($container->get('plugin.manager.workbench_access.scheme'))
      ->setUserSectionStorage($container->get('workbench_access.user_section_storage'));
  }

  /**
   * Sets manager.
   *
   * @param \Drupal\workbench_access\WorkbenchAccessManagerInterface $manager
   *   Manager.
   *
   * @return $this
   */
  public function setManager($manager) {
    $this->manager = $manager;
    return $this;
  }

  /**
   * Sets user section storage.
   *
   * @param \Drupal\workbench_access\UserSectionStorageInterface $userSectionStorage
   *   User section storage.
   *
   * @return $this
   */
  public function setUserSectionStorage($userSectionStorage) {
    $this->userSectionStorage = $userSectionStorage;
    return $this;
  }

  /**
   * Sets access scheme.
   *
   * @param \Drupal\workbench_access\Entity\AccessSchemeInterface $scheme
   *   Access scheme.
   *
   * @return $this
   */
  public function setScheme(AccessSchemeInterface $scheme) {
    $this->scheme = $scheme;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getValueOptions() {
    if (isset($this->valueOptions)) {
      return $this->valueOptions;
    }
    $this->valueOptions = [];
    if (!empty($this->scheme)) {
      $scheme = $this->scheme->getAccessScheme();
      if ($this->manager->userInAll($this->scheme)) {
        $list = WorkbenchAccessManager::getAllSections($this->scheme, FALSE);
      }
      else {
        $list = $this->userSectionStorage->getUserSections($this->scheme);
      }
      foreach($list as $id) {
        if ($section = $scheme->load($id)) {
          $this->valueOptions[$id] = str_repeat('-', $section['depth']) . ' ' . $section['label'];
        }
      }
    }
    return $this->valueOptions;
  }

  /**
   * {@inheritdoc}
   */
  protected function defineOptions() {
    $options = parent::defineOptions();

    $options['operator']['default'] = 'in';
    $options['value']['default'] = ['All'];
    $options['expose']['contains']['reduce'] = ['default' => TRUE];
    $options['section_filter']['contains']['show_hierarchy'] = ['default' => TRUE];

    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultExposeOptions() {
    parent::defaultExposeOptions();
    $this->options['expose']['reduce'] = TRUE;
  }

  /**
   * {@inheritdoc}
   */
  function operators() {
    $operators = [
      'in' => [
        'title' => $this->t('Is one of'),
        'short' => $this->t('in'),
        'short_single' => $this->t('='),
        'method' => 'opSimple',
        'values' => 1,
      ],
      'not in' => [
        'title' => $this->t('Is not one of'),
        'short' => $this->t('not in'),
        'short_single' => $this->t('<>'),
        'method' => 'opSimple',
        'values' => 1,
      ],
    ];
    return $operators;
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    parent::buildOptionsForm($form, $form_state);
    $form['section_filter']['show_hierarchy'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show children'),
      '#default_value' => !empty($this->options['section_filter']['show_hierarchy']),
      '#description' => $this->t('If checked, the filter will return the selected item and all its children.'),
    ];
  }

  /**
   * {@inheritdoc}
   *
   * Check to see if input from the exposed filters should change
   * the behavior of this filter.
   *
   * We change this default behavior, since our "Any" result should be filtered
   * by the user's assignments.
   */
  public function acceptExposedInput($input) {
    if (empty($this->options['exposed'])) {
      return TRUE;
    }

    if (!empty($this->options['expose']['use_operator']) && !empty($this->options['expose']['operator_id']) && isset($input[$this->options['expose']['operator_id']])) {
      $this->operator = $input[$this->options['expose']['operator_id']];
    }

    if (!empty($this->options['expose']['identifier'])) {
      $value = $input[$this->options['expose']['identifier']];

      // Various ways to check for the absence of non-required input.
      if (empty($this->options['expose']['required'])) {
        if (($this->operator == 'empty' || $this->operator == 'not empty') && $value === '') {
          $value = ' ';
        }
      }

      // We removed two clauses here that cause the filter to be ignored.
      if (isset($value)) {
        $this->value = $value;
        if (empty($this->alwaysMultiple) && empty($this->options['expose']['multiple']) && !is_array($value)) {
          $this->value = [$value];
        }
      }
      else {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function query() {
    $helper = new ManyToOneHelper($this);
    // The 'All' selection must be filtered by user sections.
    if (empty($this->value) || strtolower(current($this->value)) == 'all') {
      if ($this->manager->userInAll($this->scheme)) {
        return;
      }
      else {
        // This method will get all user sections and children.
        $values = $this->userSectionStorage->getUserSections($this->scheme);
      }
    }
    if (!empty($this->table)) {
      $alias = $this->query->ensureTable($this->table);
      foreach ($this->scheme->getAccessScheme()->getViewsJoin($this->getEntityType(), $this->realField, $alias) as $configuration) {
        // Allow subquery JOINs, which Menu uses.
        $type = 'standard';
        if (isset($configuration['left_query'])) {
          $type = 'subquery';
        }
        $join = Views::pluginManager('join')->createInstance($type, $configuration);
        $this->tableAlias = $helper->addTable($join, $configuration['table_alias']);
        $this->realField = $configuration['real_field'];
      }
      // If 'All' was not selected, fetch the query values.
      if (!isset($values)) {
        $values = $this->value;
      }
      if (!empty($this->options['section_filter']['show_hierarchy'])) {
        $values = $this->getChildren($values);
      }
      // If values, add our standard where clause.
      if (!empty($values)) {
        $this->scheme->getAccessScheme()->addWhere($this, $values, $alias);
      }
      // Else add a failing where clause.
      else {
        $this->query->addWhere($this->options['group'], '1 = 0');
      }
    }
  }

  /**
   * Gets the child sections of a base section.
   *
   * @param array $values
   *   Defined or selected values.
   *
   * @return array
   *   An array of section ids that this user may see.
   */
  protected function getChildren($values) {
    $tree = $this->scheme->getAccessScheme()->getTree();
    $children = [];
    foreach ($values as $id) {
      foreach ($tree as $key => $data) {
        if ($id == $key) {
          $children += array_keys($data);
        }
        else {
          foreach ($data as $iid => $item) {
            if ($iid == $id || in_array($id, $item['parents'])) {
              $children[] = $iid;
            }
          }
        }
      }
    }
    return $children;
  }

}
