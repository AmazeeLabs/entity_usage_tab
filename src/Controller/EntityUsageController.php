<?php

namespace Drupal\entity_usage\Controller;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\field\FieldStorageConfigInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Entity\Query\QueryFactory;
use Drupal\Core\Entity\EntityFieldManager;

/**
 * Class EntityUsageController.
 *
 * @package Drupal\entity_usage\Controller
 */
class EntityUsageController extends ControllerBase {

  /**
   * Drupal\Core\Entity\EntityTypeManager definition.
   *
   * @var \Drupal\Core\Entity\EntityTypeManager
   */
  protected $entityTypeManager;

  /**
   * Drupal\Core\Entity\Query\QueryFactory definition.
   *
   * @var \Drupal\Core\Entity\Query\QueryFactory
   */
  protected $entityQuery;

  /**
   * Drupal\Core\Entity\EntityFieldManager definition.
   *
   * @var \Drupal\Core\Entity\EntityFieldManager
   */
  protected $entityFieldManager;

  /**
   * The entity repository.
   *
   * @var \Drupal\Core\Entity\EntityRepositoryInterface
   */
  protected $entityRepository;


  /**
   * The entity in usage.
   *
   * @var \Drupal\Core\Entity\EntityInterface
   */
  protected $entityUsage;

  /**
   * {@inheritdoc}
   */
  public function __construct(EntityTypeManager $entity_type_manager, QueryFactory $entity_query, EntityFieldManager $entity_field_manager, EntityRepositoryInterface $entity_repository) {
    $this->entityTypeManager = $entity_type_manager;
    $this->entityQuery = $entity_query;
    $this->entityFieldManager = $entity_field_manager;
    $this->entityRepository = $entity_repository;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('entity.query'),
      $container->get('entity_field.manager'),
      $container->get('entity.repository')
    );
  }

  /**
   * @return \Drupal\Core\Entity\EntityInterface
   */
  protected function getEntityUsage() {
    return $this->entityUsage;
  }

  /**
   * @param $entity \Drupal\Core\Entity\EntityInterface
   */
  protected function setEntityUsage($entity) {
    $this->entityUsage = $entity;
  }

  /**
   * List of reference field types.
   *
   * @var array
   */
  public static $SUPPORTED_FIELD_TYPES = [
    'entity_reference',
    'entity_reference_revisions',
  ];

  /**
   * Links and their labels.
   *
   * @var array
   */
  public static $LINKS = [
    'canonical' => 'view',
    'edit-form' => 'edit',
  ];

  /**
   * Usage page.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   * @return array Renderable array of entity usages.
   *   Renderable array of entity usages.
   */
  public function list(RouteMatchInterface $route_match) {
    $entity = $this->getEntityFromRouteMatch($route_match);
    $this->setEntityUsage($entity);
    $rows = [];

    foreach ($this->getReferencingEntitiesWithParents($entity->getEntityTypeId(), $entity->id()) as $item) {
      if ($this->shouldShowItem($item)) {
        $curRow = $this->getTableRowsFromItem($item, []);

        if (!isset($rows[key($curRow)])) {
          $rows = array_merge($rows, $curRow);

        } elseif (isset($rows[key($curRow)]['location']) && isset($curRow[key($curRow)]['location'])) {
          // Reduce duplicate locations caused by finding all translations of an entity.
          if (strpos($rows[key($curRow)]['location'], $curRow[key($curRow)]['location']) === FALSE) {
            $rows[key($curRow)]['location'] = new FormattableMarkup('@current<br>@new', [
              '@current' => $rows[key($curRow)]['location'],
              '@new' => $curRow[key($curRow)]['location'],
            ]);

          }
        }
      }
    }

    if (empty($rows)) {
      return [
        '#markup' => $this->t('This entity is not referenced by any other entity.'),
      ];
    }

    return [
      '#theme' => 'table',
      '#rows' => $rows,
      '#header' => [
        $this->t('Admin Title / Title'),
        $this->t('Location(s)'),
        $this->t('View'),
        $this->t('Edit'),
      ],
    ];
  }

  /**
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   * @param \Drupal\Core\Entity\EntityInterface|NULL $entity
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   */
  public function getTitle(RouteMatchInterface $route_match, EntityInterface $entity = NULL) {
    if (!isset($entity)) {
      foreach ($route_match->getParameters() as $parameter) {
        if ($parameter instanceof EntityInterface) {
          $entity = $parameter;
          break;
        }
      }
    }

    if (isset($entity)) {
      $translatedEntity = $this->entityRepository->getTranslationFromContext($entity);
    }

    $name = isset($translatedEntity) && $translatedEntity->hasField('name') ? $translatedEntity->get('name')->value : 'entity';
    $bundle = isset($translatedEntity) ? ucwords($translatedEntity->bundle()) : '';
    $type = isset($translatedEntity) ? ucwords($translatedEntity->getEntityTypeId()) : '';

    return $this->t('Entity usage of @bundle "@name" (@type)', ['@bundle' => $bundle, '@name' => $name, '@type' => $type]);
  }

  /**
   * Checks if given item should be rendered. Entities that cannot be reached
   * and are not referenced by another entities are filtered out.
   *
   * @param array $item
   *   Usage item.
   * @return bool
   */
  protected function shouldShowItem(array $item) {
    /** @var \Drupal\Core\Entity\EntityInterface $entity */
    $entity = $item['entity'];

    // If entity is not embedded anywhere and has no standalone page then hide it.
    if (!$entity->hasLinkTemplate('canonical') && empty($item['parents'])) {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Tells if this item should be the last which is displayed in the chain. Once
   * an entity that has a page display (canonical link template) is spotted
   * there's no need to go further.
   *
   * @param EntityInterface $entity
   *   The entity.
   * @return bool
   */
  protected function shouldBreakRendering($entity) {
    return $entity->hasLinkTemplate('canonical');
  }

  /**
   * Returns all table rows associated with given item.
   *
   * @param array $item
   *   Usage item.
   * @param array $route
   *   Visited nodes.
   * @return array
   */
  protected function getTableRowsFromItem($item, $route) {
    /** @var \Drupal\Core\Entity\EntityInterface $entity */
    $entity = $item['entity'];

    $route[] = $entity;

    if ($this->shouldBreakRendering($entity)) {
      return [$entity->getType() . ':' . $entity->id() => $this->buildEntityRow($entity, $route)];
    }

    $rows = [];

    foreach ($item['parents'] as $parent) {
      if ($row = $this->getTableRowsFromItem($parent, $route)) {
        $rows = array_merge($rows, $row);
      }
    }

    return $rows;
  }

  /**
   * Builds a table row representing given entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   * @param EntityInterface[] $route
   *   Nesting level.
   * @return array
   *   Single row.
   */
  protected function buildEntityRow(EntityInterface $entity, $route) {
    array_pop($route);
    $routeWithoutNode = array_reverse($route);
    $formattedRoute = implode(' -> ', array_map([$this, 'formatEntityTitle'], $routeWithoutNode));

    if (empty($route) && method_exists($entity, 'getFieldDefinitions')) {
      // Find out the label of which field on the node is being referenced by entity reference.
      foreach ($entity->getFieldDefinitions() as $fieldName => $fieldDefinition) {
        if ($fieldDefinition->getType() === 'entity_reference') {
          foreach ($entity->get($fieldName) as $referencedItem) {
            if ($referencedItem->entity->id() === $this->getEntityUsage()->id()) {
              $formattedRoute = $fieldDefinition->getLabel();
              break 2;
            }
          }
        }
      }
    }

    if ($entity instanceof FieldableEntityInterface &&
      $entity->hasField('field_admin_title') &&
      ($admin_title_field = $entity->get('field_admin_title')) &&
      !$admin_title_field->isEmpty()) {
      $row['title'] = $this->t('@admintitle<br/><small>@label: @title</small>', [
          '@admintitle' => $entity->toLink($admin_title_field->value)->toString(),
          '@label' => t('Public title'),
          '@title' => $entity->toLink($entity->label())->toString(),
        ]);
    } else {
      $row['title'] = $entity->toLink($entity->label());
    }

    $row['location'] = $formattedRoute;

    // Display links only for leaf items.
    foreach (static::$LINKS as $rel => $text) {
      if ($entity->hasLinkTemplate($rel) && $this->shouldBreakRendering($entity)) {
        $row[$text] = $entity->toLink($text, $rel);
      } else {
        $row[$text] = '';
      }
    }

    return $row;
  }

  /**
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   *
   * @return string
   */
  protected function formatEntityTitle(EntityInterface $entity) {
    if (method_exists($entity, 'hasField') && $entity->hasField('field_twm_title')) {
      $title = $entity->get('field_twm_title')->value;
    } elseif (method_exists($entity, 'hasField') && $entity->hasField('field_hss_title')) {
      $title = $entity->get('field_hss_title')->value;
    } elseif (method_exists($entity, 'hasField') && $entity->hasField('field_title')) {
      $title = $entity->get('field_title')->value;
    } elseif (method_exists($entity, 'hasField') && $entity->hasField('title')) {
      $title = $entity->get('title')->value;
    } elseif (method_exists($entity, 'getTitle')) {
      $title = $entity->getTitle();
    } elseif (method_exists($entity, 'getParentEntity') && $parent = $entity->getParentEntity()) {
      $parent_field = $entity->get('parent_field_name')->value;
      $values = $parent->{$parent_field};
      foreach ($values as $key => $value) {
        if ($value->entity->id() == $entity->id()) {
          return $this->t('@title (@label)', [
            '@title' => $value->getFieldDefinition()->getLabel(),
            '@label' => ucwords($value->entity->getParagraphType()->label()),
          ]);
        }
      }
    }

    if (method_exists($entity, 'getParagraphType')) {
      if (empty($title)) {
        $title = $entity->getParagraphType()->label();
      } else {
        $title = $this->t('@title (@label)', [
          '@title' => $title,
          '@label' => $entity->getParagraphType()->label(),
          ]);
      }
    }

    return $title;

  }

  /**
   * Returns list of entities that reference given entity and all their
   * ancestors.
   *
   * @param string $entityType
   *   Entity type id.
   * @param int $entityId
   *   Id of the entity.
   * @return array
   *   UsageItem: [
   *     'entity' => EntityInterface
   *     'parents' => UsageItem[]
   *   ]
   */
  protected function getReferencingEntitiesWithParents($entityType, $entityId) {
    $result = [];

    foreach ($this->getReferencingEntities($entityType, $entityId) as $entity) {
      $result[$entity->getEntityTypeId() . ':' . $entity->id()] = [
        'entity' => $entity,
        'parents' => $this->getReferencingEntitiesWithParents($entity->getEntityTypeId(), $entity->id()),
      ];
    }

    return $result;
  }

  /**
   * Return list of entities directly referencing given entity.
   *
   * @param string $entityType
   *   Entity type id.
   * @param int $entityId
   *   Id of the entity.
   * @return EntityInterface[]
   *   Array of parent entities.
   */
  protected function getReferencingEntities($entityType, $entityId) {
    $entities = [];

    foreach ($this->getReferencingFields($entityType) as $referencingEntityType => $fields) {
      $query = $this
        ->entityQuery
        ->get($referencingEntityType, 'OR')
        ->sort('created', 'DESC');

      foreach ($fields as $field) {
        $query->condition($field, $entityId);
      }

      $result = $query->execute();

      if (!empty($result)) {
        $entities = array_merge($entities, $this
          ->entityTypeManager
          ->getStorage($referencingEntityType)
          ->loadMultiple($result)
        );
      }
    }

    return $entities;
  }

  /**
   * Returns fields that reference given entity type.
   *
   * @param string $targetEntityType
   *   Entity type id.
   *
   * @return string[]
   *   List of field ids keyed by entity type.
   */
  protected function getReferencingFields($targetEntityType) {
    $fields = [];

    foreach ($this->entityTypeManager->getDefinitions() as $entityType => $definition) {
      if ($definition->isSubclassOf(FieldableEntityInterface::class)) {

        /** @var FieldDefinitionInterface $fieldStorage */
        foreach ($this->entityFieldManager->getFieldStorageDefinitions($entityType) as $fieldStorage) {
          if (
            $fieldStorage instanceof FieldStorageConfigInterface
            && in_array($fieldStorage->getType(), static::$SUPPORTED_FIELD_TYPES)
            && $fieldStorage->getSetting('target_type') == $targetEntityType
          ) {
            $fields[$entityType][] = $fieldStorage->getName();
          }
        }
      }
    }

    return $fields;
  }

  /**
   * Retrieves entity from route match.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The entity object as determined from the passed-in route match.
   */
  protected function getEntityFromRouteMatch(RouteMatchInterface $route_match) {
    $parameter_name = $route_match->getRouteObject()->getOption('_entity_usage_entity_type');
    $entity = $route_match->getParameter($parameter_name);
    return $entity;
  }

}
