<?php

namespace Drupal\stripe_subscription\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;

/**
 * Event subscriber for provider node creation.
 */
class ProviderNodeSubscriber implements EventSubscriberInterface {

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * Constructs a new ProviderNodeSubscriber object.
   *
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   */
  public function __construct(
    AccountProxyInterface $current_user,
    RouteMatchInterface $route_match,
    ConfigFactoryInterface $config_factory,
    MessengerInterface $messenger
  ) {
    $this->currentUser = $current_user;
    $this->routeMatch = $route_match;
    $this->configFactory = $config_factory;
    $this->messenger = $messenger;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      // Disabled event subscription
      // KernelEvents::RESPONSE => ['onKernelResponse', 100],
    ];
  }

  /**
   * Handles the kernel response event.
   *
   * @param \Symfony\Component\HttpKernel\Event\ResponseEvent $event
   *   The response event.
   */
  public function onKernelResponse(ResponseEvent $event) {
    // Functionality disabled
  }

}