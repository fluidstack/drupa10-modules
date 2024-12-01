<?php

namespace Drupal\stripe_subscription\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\stripe_subscription\StripeSubscriptionManager;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Url;
use Drupal\user\Entity\User;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Ajax\RedirectCommand;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Provides a payment form for Stripe subscriptions.
 */
class StripePaymentForm extends FormBase {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The stripe subscription manager.
   *
   * @var \Drupal\stripe_subscription\StripeSubscriptionManager
   */
  protected $subscriptionManager;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * Constructs a new StripePaymentForm object.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    StripeSubscriptionManager $subscription_manager,
    MessengerInterface $messenger,
    LoggerChannelFactoryInterface $logger_factory,
    AccountProxyInterface $current_user
  ) {
    $this->configFactory = $config_factory;
    $this->subscriptionManager = $subscription_manager;
    $this->messenger = $messenger;
    $this->loggerFactory = $logger_factory;
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('stripe_subscription.subscription_manager'),
      $container->get('messenger'),
      $container->get('logger.factory'),
      $container->get('current_user')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'stripe_payment_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->configFactory->get('stripe_subscription.settings');
    $publishable_key = $config->get('stripe_publishable_key');
    $api_key = $config->get('stripe_api_key');

    if (!$publishable_key || !$api_key) {
      $this->messenger->addError($this->t('Stripe is not properly configured. Please contact the site administrator.'));
      return [
        '#markup' => $this->t('Payment form is currently unavailable.'),
      ];
    }

    try {
      \Stripe\Stripe::setApiKey($api_key);

      // Get subscription details
      $price = \Stripe\Price::retrieve($config->get('stripe_price_id'));
      $amount = $price->unit_amount / 100;
      $gst = $amount / 11; // GST is 1/11th of total for 10% tax
      $subtotal = $amount - $gst;

      // Add form theme and wrapper
      $form['#theme'] = 'stripe_payment_form';
      $form['#prefix'] = '<div id="stripe-payment-form-wrapper">';
      $form['#suffix'] = '</div>';
      $form['#attributes'] = [
        'id' => 'stripe-payment-form',
        'class' => ['stripe-payment-form'],
      ];

      // Add subscription details as template variables
      $form['#subscription'] = [
        'plan_name' => $price->nickname ?? 'Provider Subscription',
        'interval' => $price->recurring->interval,
        'amount' => number_format($amount, 2),
        'currency' => strtoupper($price->currency),
        'gst' => number_format($gst, 2),
        'subtotal' => number_format($subtotal, 2),
        'total' => number_format($amount, 2),
      ];

      // Pass subscription details to drupalSettings for JS
      $form['#attached'] = [
        'library' => ['stripe_subscription/stripe'],
        'drupalSettings' => [
          'stripeSubscription' => [
            'publishableKey' => $publishable_key,
            'processUrl' => '/subscription/payment/process',
            'userEmail' => $this->currentUser->getEmail(),
            'amount' => $price->unit_amount,
            'currency' => $price->currency,
            'subscription' => $form['#subscription'],
          ],
        ],
      ];

      // Form elements
      $form['message'] = [
        '#type' => 'markup',
        '#markup' => '<div class="payment-intro">' . $this->t('Please review your payment details and enter your payment information below.') . '</div>',
      ];

      $form['stripe_element'] = [
        '#type' => 'container',
        '#attributes' => [
          'id' => 'payment-element',
          'class' => ['form-item'],
        ],
      ];

      $form['payment_errors'] = [
        '#type' => 'container',
        '#attributes' => [
          'id' => 'payment-errors',
          'class' => ['messages', 'messages--error'],
          'style' => 'display: none;',
        ],
      ];

      $form['actions'] = [
        '#type' => 'actions',
      ];

      $form['actions']['submit'] = [
        '#type' => 'submit',
        '#value' => $this->t('Complete Payment'),
        '#attributes' => [
          'id' => 'stripe-submit',
          'class' => ['button', 'button--primary'],
        ],
        '#ajax' => [
          'callback' => '::submitFormAjax',
          'wrapper' => 'stripe-payment-form-wrapper',
        ],
      ];

      return $form;
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('stripe_subscription')->error('Payment form error: @message', [
        '@message' => $e->getMessage(),
      ]);
      $this->messenger->addError($this->t('An error occurred while loading the payment form. Please try again later.'));
      return [
        '#markup' => $this->t('Payment form is currently unavailable.'),
      ];
    }
  }

  /**
   * Ajax callback for form submission.
   */
  public function submitFormAjax(array &$form, FormStateInterface $form_state) {
    $response = new AjaxResponse();

    try {
      // Load current user
      $user = User::load($this->currentUser->id());
      if (!$user) {
        throw new \Exception('User not found');
      }

      // Get the paid role from configuration
      $config = $this->configFactory->get('stripe_subscription.settings');
      $paid_role = $config->get('paid_user_role');

      if (!$paid_role) {
        throw new \Exception('Paid role not configured');
      }

      // Add the paid role if not already assigned
      if (!$user->hasRole($paid_role)) {
        $user->addRole($paid_role);
        $user->save();

        $this->loggerFactory->get('stripe_subscription')->notice('Payment successful, @role role assigned to user @uid', [
          '@role' => $paid_role,
          '@uid' => $user->id(),
        ]);

        // Store success message in session
        $_SESSION['payment_completed'] = TRUE;

        // Create success message with provider link
        $provider_url = Url::fromRoute('entity.node.add', ['node_type' => 'provider'])->toString();
        $success_message = '<div class="payment-success">' .
          '<h3>' . $this->t('Payment Successful!') . '</h3>' .
          '<p>' . $this->t('Thank you for your payment. Your provider subscription is now active.') . '</p>' .
          '<p>' . $this->t('Your subscription will renew automatically.') . '</p>' .
          '<a href="' . $provider_url . '" class="button button--primary">' . 
          $this->t('Add your Business now') . '</a>' .
          '</div>';

        $response->addCommand(new HtmlCommand('#stripe-payment-form-wrapper', $success_message));
        $response->addCommand(new RedirectCommand('/mydashboard'));
      }
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('stripe_subscription')->error('Payment processing error: @message', [
        '@message' => $e->getMessage(),
      ]);
      
      $response->addCommand(new HtmlCommand(
        '#payment-errors',
        '<div class="messages messages--error">' . $e->getMessage() . '</div>'
      ));
    }

    return $response;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    // Validation is handled client-side by Stripe Elements
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    try {
      // Load current user
      $user = User::load($this->currentUser->id());
      if (!$user) {
        throw new \Exception('User not found');
      }

      // Get the paid role from configuration
      $config = $this->configFactory->get('stripe_subscription.settings');
      $paid_role = $config->get('paid_user_role');

      if (!$paid_role) {
        throw new \Exception('Paid role not configured');
      }

      // Add the paid role if not already assigned
      if (!$user->hasRole($paid_role)) {
        $user->addRole($paid_role);
        $user->save();

        $this->loggerFactory->get('stripe_subscription')->notice('Payment successful, @role role assigned to user @uid', [
          '@role' => $paid_role,
          '@uid' => $user->id(),
        ]);

        // Store success message in session
        $_SESSION['payment_completed'] = TRUE;

        // Set success message
        $this->messenger->addStatus($this->t('Thank you for your payment. Your provider subscription is now active.'));

        // Redirect to dashboard
        $form_state->setRedirect('user.page');
      }
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('stripe_subscription')->error('Payment processing error: @message', [
        '@message' => $e->getMessage(),
      ]);
      $this->messenger->addError($this->t('An error occurred while processing your payment. Please try again.'));
    }
  }

}