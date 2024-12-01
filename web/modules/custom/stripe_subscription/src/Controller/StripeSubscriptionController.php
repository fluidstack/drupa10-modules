<?php

namespace Drupal\stripe_subscription\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\stripe_subscription\StripeSubscriptionManager;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\stripe_subscription\PdfGenerator;
use Drupal\user\Entity\User;

/**
 * Controller for handling Stripe subscription operations.
 */
class StripeSubscriptionController extends ControllerBase {

  /**
   * The stripe subscription manager.
   *
   * @var \Drupal\stripe_subscription\StripeSubscriptionManager
   */
  protected $subscriptionManager;

  /**
   * The date formatter.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * The renderer.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * The PDF generator.
   *
   * @var \Drupal\stripe_subscription\PdfGenerator
   */
  protected $pdfGenerator;

  /**
   * Constructs a new StripeSubscriptionController object.
   *
   * @param \Drupal\stripe_subscription\StripeSubscriptionManager $subscription_manager
   *   The subscription manager.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $date_formatter
   *   The date formatter.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer.
   * @param \Drupal\stripe_subscription\PdfGenerator $pdf_generator
   *   The PDF generator.
   */
  public function __construct(
    StripeSubscriptionManager $subscription_manager,
    DateFormatterInterface $date_formatter,
    LoggerChannelFactoryInterface $logger_factory,
    RendererInterface $renderer,
    PdfGenerator $pdf_generator
  ) {
    $this->subscriptionManager = $subscription_manager;
    $this->dateFormatter = $date_formatter;
    $this->loggerFactory = $logger_factory;
    $this->renderer = $renderer;
    $this->pdfGenerator = $pdf_generator;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('stripe_subscription.subscription_manager'),
      $container->get('date.formatter'),
      $container->get('logger.factory'),
      $container->get('renderer'),
      $container->get('stripe_subscription.pdf_generator')
    );
  }

  /**
   * Processes the payment and creates a subscription.
   */
  public function processPayment(Request $request) {
    try {
      $data = json_decode($request->getContent(), TRUE);
      $payment_method_id = $data['payment_method_id'] ?? NULL;
      $promotion_code = $data['promotion_code'] ?? NULL;

      if (!$payment_method_id) {
        throw new \Exception('Payment method ID is required');
      }

      // Create subscription using the subscription manager
      $result = $this->subscriptionManager->createSubscription($payment_method_id, $promotion_code);

      return new JsonResponse($result);
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('stripe_subscription')->error('Payment processing error: @message', [
        '@message' => $e->getMessage(),
      ]);
      
      return new JsonResponse([
        'status' => 'error',
        'message' => $e->getMessage(),
      ], 400);
    }
  }

  /**
   * Validates a coupon code.
   */
  public function validateCoupon(Request $request) {
    try {
      $data = json_decode($request->getContent(), TRUE);
      $coupon = $data['coupon'] ?? NULL;

      if (!$coupon) {
        throw new \Exception('Coupon code is required');
      }

      $config = $this->config('stripe_subscription.settings');
      \Stripe\Stripe::setApiKey($config->get('stripe_api_key'));

      // Retrieve the promotion code
      $promotion_codes = \Stripe\PromotionCode::all([
        'code' => $coupon,
        'active' => true,
        'limit' => 1,
      ]);

      if (empty($promotion_codes->data)) {
        return new JsonResponse([
          'valid' => false,
          'message' => $this->t('Invalid or expired promotion code'),
        ]);
      }

      $promotion_code = $promotion_codes->data[0];
      $coupon = $promotion_code->coupon;

      // Calculate the discounted amount
      $price = \Stripe\Price::retrieve($config->get('stripe_price_id'));
      $original_amount = $price->unit_amount;
      $final_amount = $original_amount;

      if ($coupon->amount_off) {
        $final_amount = max(0, $original_amount - $coupon->amount_off);
        $description = sprintf('$%d off', $coupon->amount_off / 100);
      }
      elseif ($coupon->percent_off) {
        $final_amount = $original_amount * (1 - ($coupon->percent_off / 100));
        $description = sprintf('%d%% off', $coupon->percent_off);
      }

      return new JsonResponse([
        'valid' => true,
        'promotion_code_id' => $promotion_code->id,
        'description' => $description,
        'final_amount' => $final_amount / 100,
        'original_amount' => $original_amount / 100,
      ]);
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('stripe_subscription')->error('Coupon validation error: @message', [
        '@message' => $e->getMessage(),
      ]);

      return new JsonResponse([
        'valid' => false,
        'message' => $this->t('Error validating promotion code'),
      ], 400);
    }
  }

  /**
   * Displays subscription details for a user.
   */
  public function subscriptionDetails(AccountInterface $user) {
    $subscription = $this->subscriptionManager->getSubscriptionDetails($user->id());

    if (!$subscription) {
      return [
        '#markup' => $this->t('No active subscription found.'),
      ];
    }

    // Format the renewal date
    $renewal_date = $this->dateFormatter->format(
      $subscription['current_period_end'],
      'custom',
      'j F Y'
    );

    try {
      // Get invoices for tax receipts
      $config = $this->config('stripe_subscription.settings');
      \Stripe\Stripe::setApiKey($config->get('stripe_api_key'));

      $invoices = \Stripe\Invoice::all([
        'customer' => $user->get('field_stripe_customer_id')->value,
        'status' => 'paid',
        'limit' => 12,
        'expand' => ['data.discount'],
      ]);

      $formatted_invoices = [];
      foreach ($invoices->data as $invoice) {
        $invoice_data = [
          'id' => $invoice->id,
          'date' => $this->dateFormatter->format($invoice->created, 'custom', 'j F Y'),
          'amount' => number_format($invoice->total / 100, 2),
        ];

        if ($invoice->discount) {
          $invoice_data['discount'] = $invoice->discount->coupon->amount_off ?
            sprintf('$%d off', $invoice->discount->coupon->amount_off / 100) :
            sprintf('%d%% off', $invoice->discount->coupon->percent_off);
        }

        $formatted_invoices[] = $invoice_data;
      }

      // Format the amount with decimals
      $amount = number_format($subscription['amount'] / 100, 2);

      // Prepare subscription details for template
      $subscription_details = [
        'status' => $subscription['status'],
        'plan_name' => $subscription['plan_name'],
        'amount' => $amount,
        'currency' => strtoupper($subscription['currency']),
        'renewal_date' => $renewal_date,
        'interval' => $subscription['interval'],
        'invoices' => $formatted_invoices,
      ];

      if (!empty($subscription['discount'])) {
        $subscription_details['discount'] = $subscription['discount'];
        $subscription_details['original_amount'] = number_format($subscription['original_amount'] / 100, 2);
      }

      return [
        '#theme' => 'stripe_subscription_details',
        '#subscription' => $subscription_details,
        '#attached' => [
          'library' => ['stripe_subscription/subscription_details'],
        ],
      ];
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Unable to load subscription details: @error', [
        '@error' => $e->getMessage(),
      ]));

      return [
        '#markup' => $this->t('An error occurred while loading subscription details.'),
      ];
    }
  }

  /**
   * Downloads a tax receipt for a specific invoice.
   */
  public function downloadTaxReceipt(AccountInterface $user, $invoice_id) {
    try {
      $config = $this->config('stripe_subscription.settings');
      \Stripe\Stripe::setApiKey($config->get('stripe_api_key'));

      // Fetch invoice from Stripe
      $invoice = \Stripe\Invoice::retrieve([
        'id' => $invoice_id,
        'expand' => ['discount'],
      ]);
      
      // Get business details
      $business_name = $user->get('field_business_name')->value;
      $abn = $user->get('field_abn')->value;

      // Calculate amounts
      $subtotal = $invoice->subtotal;
      if ($invoice->discount) {
        if ($invoice->discount->coupon->amount_off) {
          $subtotal -= $invoice->discount->coupon->amount_off;
        } 
        elseif ($invoice->discount->coupon->percent_off) {
          $subtotal = $subtotal * (1 - ($invoice->discount->coupon->percent_off / 100));
        }
      }

      // Calculate GST (10% for Australia)
      $gst = $subtotal / 11; // GST is 1/11th of total for 10% tax
      $subtotal = $subtotal - $gst;

      // Prepare receipt data
      $receipt_data = [
        'invoice_number' => $invoice->number,
        'date' => $this->dateFormatter->format($invoice->created, 'custom', 'j F Y'),
        'business_name' => $business_name,
        'abn' => $abn,
        'email' => $user->getEmail(),
        'description' => $invoice->lines->data[0]->description,
        'subtotal' => number_format($subtotal / 100, 2),
        'gst' => number_format($gst / 100, 2),
        'total' => number_format($invoice->total / 100, 2),
        'currency' => strtoupper($invoice->currency),
      ];

      // Add discount information if applicable
      if ($invoice->discount) {
        $receipt_data['discount'] = $invoice->discount->coupon->amount_off ?
          sprintf('$%d off', $invoice->discount->coupon->amount_off / 100) :
          sprintf('%d%% off', $invoice->discount->coupon->percent_off);
        $receipt_data['original_amount'] = number_format($invoice->subtotal / 100, 2);
      }

      // Generate PDF using the service
      $pdf_content = $this->pdfGenerator->generatePdfFromTemplate(
        'stripe_subscription_tax_receipt',
        $receipt_data,
        ['paper' => 'A4']
      );

      // Generate response
      $response = new Response($pdf_content);
      $response->headers->set('Content-Type', 'application/pdf');
      $response->headers->set('Content-Disposition', 'attachment; filename="tax-receipt-' . $invoice->number . '.pdf"');

      return $response;
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Unable to generate tax receipt: @error', ['@error' => $e->getMessage()]));
      return $this->redirect('stripe_subscription.subscription_details', ['user' => $user->id()]);
    }
  }

}