<?php

namespace Drupal\stripe_subscription\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Provides a configuration form for Stripe Subscription settings.
 */
class StripeSubscriptionSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'stripe_subscription_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['stripe_subscription.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('stripe_subscription.settings');

    $form['stripe_api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Secret API Key'),
      '#default_value' => $config->get('stripe_api_key'),
      '#required' => TRUE,
      '#description' => $this->t('Enter your Stripe Secret API key.'),
    ];

    $form['stripe_publishable_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Stripe Publishable Key'),
      '#default_value' => $config->get('stripe_publishable_key'),
      '#required' => TRUE,
      '#description' => $this->t('Enter your Stripe publishable key.'),
    ];

    $form['stripe_price_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Stripe Price ID'),
      '#default_value' => $config->get('stripe_price_id'),
      '#required' => TRUE,
      '#description' => $this->t('Enter your Stripe Price ID (e.g., price_H2tR3...)'),
    ];

    // Get all available roles except anonymous and authenticated
    $roles = user_roles(TRUE);
    unset($roles['authenticated']);
    $role_options = [];
    foreach ($roles as $role) {
      $role_options[$role->id()] = $role->label();
    }

    $form['payment_form_role'] = [
      '#type' => 'select',
      '#title' => $this->t('Payment Form Role'),
      '#options' => $role_options,
      '#default_value' => $config->get('payment_form_role'),
      '#required' => TRUE,
      '#description' => $this->t('Select the role that will have access to the payment form.'),
    ];

    $form['paid_user_role'] = [
      '#type' => 'select',
      '#title' => $this->t('Paid User Role'),
      '#options' => $role_options,
      '#default_value' => $config->get('paid_user_role'),
      '#required' => TRUE,
      '#description' => $this->t('Select the role that will be assigned to users after successful payment.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('stripe_subscription.settings')
      ->set('stripe_api_key', $form_state->getValue('stripe_api_key'))
      ->set('stripe_publishable_key', $form_state->getValue('stripe_publishable_key'))
      ->set('stripe_price_id', $form_state->getValue('stripe_price_id'))
      ->set('payment_form_role', $form_state->getValue('payment_form_role'))
      ->set('paid_user_role', $form_state->getValue('paid_user_role'))
      ->save();

    parent::submitForm($form, $form_state);
  }
}