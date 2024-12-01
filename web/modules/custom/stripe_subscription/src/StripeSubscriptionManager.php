<?php

namespace Drupal\stripe_subscription;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\user\Entity\User;
use Drupal\Core\TempStore\PrivateTempStoreFactory;

/**
 * Service for managing Stripe subscriptions.
 */
class StripeSubscriptionManager {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * The tempstore factory.
   *
   * @var \Drupal\Core\TempStore\PrivateTempStoreFactory
   */
  protected $tempStoreFactory;

  /**
   * Constructs a new StripeSubscriptionManager.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    AccountProxyInterface $current_user,
    LoggerChannelFactoryInterface $logger_factory,
    PrivateTempStoreFactory $temp_store_factory
  ) {
    $this->configFactory = $config_factory;
    $this->currentUser = $current_user;
    $this->loggerFactory = $logger_factory;
    $this->tempStoreFactory = $temp_store_factory;
  }

  /**
   * Creates a new subscription for the current user.
   */
  public function createSubscription($payment_method_id, $promotion_code = NULL) {
    try {
      $config = $this->configFactory->get('stripe_subscription.settings');
      $api_key = $config->get('stripe_api_key');
      $price_id = $config->get('stripe_price_id');
      $paid_role = $config->get('paid_user_role');

      if (!$api_key || !$price_id || !$paid_role) {
        throw new \Exception('Missing required configuration');
      }

      \Stripe\Stripe::setApiKey($api_key);

      // Load the current user
      $user = User::load($this->currentUser->id());
      if (!$user) {
        throw new \Exception('User not found');
      }

      // Create or get Stripe customer
      $stripe_customer_id = $user->get('field_stripe_customer_id')->value;
      if (!$stripe_customer_id) {
        $customer = \Stripe\Customer::create([
          'email' => $user->getEmail(),
          'payment_method' => $payment_method_id,
          'invoice_settings' => [
            'default_payment_method' => $payment_method_id,
          ],
        ]);
        $stripe_customer_id = $customer->id;
        
        // Save Stripe customer ID to user
        $user->set('field_stripe_customer_id', $stripe_customer_id);
        $user->save();
      }

      // Prepare subscription data
      $subscription_data = [
        'customer' => $stripe_customer_id,
        'items' => [[
          'price' => $price_id,
        ]],
        'payment_behavior' => 'default_incomplete',
        'payment_settings' => [
          'payment_method_types' => ['card'],
          'save_default_payment_method' => 'on_subscription',
        ],
        'expand' => ['latest_invoice.payment_intent'],
      ];

      // Add promotion code if provided
      if ($promotion_code) {
        $subscription_data['promotion_code'] = $promotion_code;
      }

      // Create the subscription
      $subscription = \Stripe\Subscription::create($subscription_data);

      // Handle subscription status
      if ($subscription->status === 'active' || $subscription->status === 'trialing') {
        // Add paid user role if not already assigned
        if (!$user->hasRole($paid_role)) {
          $user->addRole($paid_role);
          $user->save();

          $this->loggerFactory->get('stripe_subscription')->info('Added paid role @role to user @uid', [
            '@role' => $paid_role,
            '@uid' => $user->id(),
          ]);
        }

        // Store success message in tempstore
        $this->tempStoreFactory->get('stripe_subscription')
          ->set('payment_success_message', TRUE);

        return [
          'status' => 'success',
          'subscription_id' => $subscription->id,
          'redirect_url' => '/mydashboard',
        ];
      }
      elseif ($subscription->latest_invoice->payment_intent) {
        // Store the subscription ID for later role assignment
        $this->tempStoreFactory->get('stripe_subscription')
          ->set('pending_subscription_id', $subscription->id);

        return [
          'status' => 'requires_action',
          'client_secret' => $subscription->latest_invoice->payment_intent->client_secret,
          'subscription_id' => $subscription->id,
        ];
      }

      throw new \Exception('Subscription creation failed');
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('stripe_subscription')->error('Subscription creation failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      throw $e;
    }
  }

  /**
   * Gets subscription details for a user.
   */
  public function getSubscriptionDetails($uid) {
    try {
      $config = $this->configFactory->get('stripe_subscription.settings');
      $api_key = $config->get('stripe_api_key');

      if (!$api_key) {
        $this->loggerFactory->get('stripe_subscription')->error('Stripe API key not configured');
        return NULL;
      }

      \Stripe\Stripe::setApiKey($api_key);

      $user = User::load($uid);
      if (!$user) {
        return NULL;
      }

      $stripe_customer_id = $user->get('field_stripe_customer_id')->value;
      if (!$stripe_customer_id) {
        return NULL;
      }

      // Get customer's subscriptions with expanded discount
      $subscriptions = \Stripe\Subscription::all([
        'customer' => $stripe_customer_id,
        'limit' => 1,
        'status' => 'active',
        'expand' => ['data.discount'],
      ]);

      if (empty($subscriptions->data)) {
        return NULL;
      }

      $subscription = $subscriptions->data[0];
      $price = $subscription->items->data[0]->price;

      $subscription_data = [
        'id' => $subscription->id,
        'status' => $subscription->status,
        'current_period_end' => $subscription->current_period_end,
        'plan_name' => $price->nickname ?? 'Provider Subscription',
        'amount' => $price->unit_amount,
        'currency' => $price->currency,
        'interval' => $price->recurring->interval,
      ];

      // Add discount information if available
      if ($subscription->discount) {
        $coupon = $subscription->discount->coupon;
        $original_amount = $price->unit_amount;
        
        if ($coupon->amount_off) {
          $subscription_data['amount'] = max(0, $original_amount - $coupon->amount_off);
          $subscription_data['discount'] = sprintf('$%d off', $coupon->amount_off / 100);
        }
        elseif ($coupon->percent_off) {
          $subscription_data['amount'] = $original_amount * (1 - ($coupon->percent_off / 100));
          $subscription_data['discount'] = sprintf('%d%% off', $coupon->percent_off);
        }
        
        $subscription_data['original_amount'] = $original_amount;
      }

      return $subscription_data;
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('stripe_subscription')->error('Error fetching subscription details: @message', [
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

}