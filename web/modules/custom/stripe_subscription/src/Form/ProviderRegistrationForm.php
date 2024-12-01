<?php

namespace Drupal\stripe_subscription\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Password\PasswordGeneratorInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a registration form for providers.
 */
class ProviderRegistrationForm extends FormBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The password generator.
   *
   * @var \Drupal\Core\Password\PasswordGeneratorInterface
   */
  protected $passwordGenerator;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * Constructs a new ProviderRegistrationForm.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Password\PasswordGeneratorInterface $password_generator
   *   The password generator.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    PasswordGeneratorInterface $password_generator,
    MessengerInterface $messenger
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->passwordGenerator = $password_generator;
    $this->messenger = $messenger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('password_generator'),
      $container->get('messenger')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'provider_registration_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['#attached']['library'][] = 'stripe_subscription/provider_registration';

    // Business details (at top)
    $form['business_details'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Business Details'),
      '#attributes' => ['class' => ['business-details']],
      '#weight' => -10,
    ];

    $form['business_details']['business_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Business Name'),
      '#required' => TRUE,
      '#maxlength' => 255,
      '#attributes' => [
        'aria-required' => 'true',
      ],
    ];

    $form['business_details']['abn'] = [
      '#type' => 'textfield',
      '#title' => $this->t('ABN'),
      '#required' => TRUE,
      '#maxlength' => 11,
      '#attributes' => [
        'aria-required' => 'true',
        'pattern' => '[0-9]{11}',
        'inputmode' => 'numeric',
      ],
      '#description' => $this->t('Enter your 11-digit ABN without spaces.'),
    ];

    // Account information
    $form['account'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Account Information'),
      '#attributes' => ['class' => ['account-information']],
      '#weight' => -5,
    ];

    $form['account']['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Username'),
      '#required' => TRUE,
      '#attributes' => [
        'class' => ['username'],
        'autocorrect' => 'off',
        'autocapitalize' => 'off',
        'spellcheck' => 'false',
      ],
    ];

    $form['account']['mail'] = [
      '#type' => 'email',
      '#title' => $this->t('Email address'),
      '#required' => TRUE,
    ];

    $form['account']['pass'] = [
      '#type' => 'password_confirm',
      '#size' => 25,
      '#required' => TRUE,
    ];

    // Terms and conditions with direct link
    $form['terms'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('I agree to the <a href="/termsandconditions" target="_blank">Terms and Conditions</a>'),
      '#required' => TRUE,
      '#attributes' => [
        'aria-required' => 'true',
      ],
      '#weight' => 10,
    ];

    $form['actions'] = [
      '#type' => 'actions',
      '#weight' => 20,
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Create new account'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    // Validate username
    $name = $form_state->getValue('name');
    if ($name) {
      if ($this->entityTypeManager->getStorage('user')->loadByProperties(['name' => $name])) {
        $form_state->setErrorByName('name', $this->t('The username %name is already taken.', ['%name' => $name]));
      }
    }

    // Validate email
    $mail = $form_state->getValue('mail');
    if ($mail) {
      if ($this->entityTypeManager->getStorage('user')->loadByProperties(['mail' => $mail])) {
        $form_state->setErrorByName('mail', $this->t('The email address %mail is already registered.', ['%mail' => $mail]));
      }
    }

    // Validate ABN
    $abn = $form_state->getValue(['business_details', 'abn']);
    if ($abn) {
      $abn = preg_replace('/\s+/', '', (string) $abn);
      if (!$this->validateABN($abn)) {
        $form_state->setErrorByName('business_details][abn', $this->t('Please enter a valid 11-digit ABN.'));
      }
    }

    // Validate terms acceptance
    if (!$form_state->getValue('terms')) {
      $form_state->setErrorByName('terms', $this->t('You must agree to the Terms and Conditions to register.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    try {
      $storage = $this->entityTypeManager->getStorage('user');
      $user = $storage->create();

      // Set basic user fields
      $user->setUsername($form_state->getValue('name'));
      $user->setEmail($form_state->getValue('mail'));
      $user->setPassword($form_state->getValue('pass'));
      
      // Set status to active
      $user->activate();
      
      // Get and sanitize business details
      //$business_name = $form_state->getValue(['business_details', 'business_name']);
      //$abn = $form_state->getValue(['business_details', 'abn']);
      
      // Set business fields with proper type casting
      //$user->set('field_business_name', trim((string) $business_name));
      //$user->set('field_abn', preg_replace('/\s+/', '', (string) $abn));
      $user->set('field_business_name', $form_state->getValue('business_name'));
      $user->set('field_abn', $form_state->getValue('abn'));

      // Save the Terms and Conditions acceptance.
      $user->set('field_terms_and_conditions', $form_state->getValue('terms'));
      
      // Set roles
      $user->addRole('accessi_provider');

      // Save the user
      $user->save();

      // Log the user in
      user_login_finalize($user);

      // Set success message
      $this->messenger->addStatus($this->t('Registration successful. Please create your provider listing.'));

      // Redirect to provider node creation
      $form_state->setRedirect('node.add', ['node_type' => 'provider']);
    }
    catch (\Exception $e) {
      $this->messenger->addError($this->t('An error occurred during registration. Please try again.'));
      $this->logger('stripe_subscription')->error('Provider registration error: @message', ['@message' => $e->getMessage()]);
    }
  }

  /**
   * Validates an Australian Business Number (ABN).
   *
   * @param string $abn
   *   The ABN to validate.
   *
   * @return bool
   *   TRUE if the ABN is valid, FALSE otherwise.
   */
  protected function validateABN($abn) {
    // Remove any spaces or other characters
    $abn = preg_replace('/[^0-9]/', '', (string) $abn);

    // Check if it's exactly 11 digits
    if (strlen($abn) !== 11) {
      return FALSE;
    }

    // ABN validation weights
    $weights = [10, 1, 3, 5, 7, 9, 11, 13, 15, 17, 19];

    // Subtract 1 from first digit
    $abn_digits = str_split($abn);
    $abn_digits[0] = $abn_digits[0] - 1;

    // Calculate checksum
    $sum = 0;
    for ($i = 0; $i < 11; $i++) {
      $sum += ($abn_digits[$i] * $weights[$i]);
    }

    // Valid if sum is divisible by 89
    return ($sum % 89) === 0;
  }

}