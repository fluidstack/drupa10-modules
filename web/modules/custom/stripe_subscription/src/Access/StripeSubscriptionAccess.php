<?php

namespace Drupal\stripe_subscription\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\user\Entity\User;

/**
 * Provides access control for Stripe subscription pages.
 */
class StripeSubscriptionAccess implements AccessInterface {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs a new StripeSubscriptionAccess object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->configFactory = $config_factory;
  }

  /**
   * Checks access for payment-related pages.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function checkPaymentAccess(AccountInterface $account) {
    $config = $this->configFactory->get('stripe_subscription.settings');
    $payment_form_role = $config->get('payment_form_role');

    return AccessResult::allowedIf(
      $account->isAuthenticated() && 
      $account->hasRole($payment_form_role)
    )->addCacheTags(['config:stripe_subscription.settings']);
  }

  /**
   * Checks access for subscription details page.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account.
   * @param \Drupal\user\UserInterface|string|null $user
   *   The user parameter from the route.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public static function checkSubscriptionAccess(AccountInterface $account, $user = NULL) {
    // Get the user from the parameter
    if (is_numeric($user)) {
      $user = User::load($user);
    }

    if (!$user) {
      return AccessResult::forbidden()->addCacheContexts(['user']);
    }

    // Get configuration
    $config = \Drupal::config('stripe_subscription.settings');
    $payment_form_role = $config->get('payment_form_role');
    $paid_role = $config->get('paid_user_role');

    // Allow access if:
    // 1. User is viewing their own account AND
    // 2. User has either the payment form role or paid role
    return AccessResult::allowedIf(
      $account->isAuthenticated() &&
      $account->id() === $user->id() &&
      ($account->hasRole($payment_form_role) || $account->hasRole($paid_role))
    )->addCacheTags(['config:stripe_subscription.settings'])
     ->addCacheContexts(['user']);
  }

}