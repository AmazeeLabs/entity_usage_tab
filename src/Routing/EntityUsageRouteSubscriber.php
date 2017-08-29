<?php

namespace Drupal\entity_usage\Routing;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Class EntityUsageRouteSubscriber.
 *
 * @package Drupal\entity_usage\Routing
 * Listens to the dynamic route events.
 */
class EntityUsageRouteSubscriber extends RouteSubscriberBase {

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a new RouteSubscriber object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection) {
    foreach ($this->entityTypeManager->getDefinitions() as $entityType => $definition) {
      if ($template = $definition->getLinkTemplate('entity-usage')) {
        $route = new Route($template);
        $route
          ->addDefaults([
            '_controller' => '\Drupal\entity_usage\Controller\EntityUsageController::list',
            '_title' => 'Entity usage',
            'entity_type' => $entityType,
          ])
          ->addRequirements([
            '_permission' => 'access entity usage information',
          ])
          ->setOption('_admin_route', TRUE)
          ->setOption('_entity_usage_entity_type', $entityType)
          ->setOption('parameters', [
            $entityType => ['type' => 'entity:' . $entityType],
          ]);

        $collection->add("entity.$entityType.entity_usage", $route);
      }
    }
  }

}
