// Import jQuery
import jQuery from 'jquery';
window.jQuery = window.$ = jQuery;

(function ($, Drupal, drupalSettings) {
  'use strict';

  // Utility function to update payment summary amounts
  function updatePaymentSummary(subtotal, gst, total) {
    const amounts = document.querySelectorAll('.payment-summary__amount');
    const [subtotalEl, gstEl, totalEl] = Array.from(amounts);
    
    if (subtotalEl) subtotalEl.textContent = '$' + subtotal;
    if (gstEl) gstEl.textContent = '$' + gst;
    if (totalEl) totalEl.textContent = '$' + total;
  }

  // Utility function to toggle loading state
  function toggleLoading(show, message = 'Processing payment...') {
    const loadingOverlay = document.querySelector('.loading-overlay');
    const loadingText = loadingOverlay.querySelector('.loading-text');
    
    if (show) {
      loadingText.textContent = message;
      loadingOverlay.classList.add('active');
    } else {
      loadingOverlay.classList.remove('active');
    }
  }

  Drupal.behaviors.stripeSubscription = {
    attach: function (context, settings) {
      if (context !== document) {
        return;
      }

      var formWrapper = document.getElementById('stripe-payment-form-wrapper');
      if (!formWrapper) {
        return;
      }

      // Add loading overlay to the form wrapper
      const loadingOverlay = document.createElement('div');
      loadingOverlay.className = 'loading-overlay';
      loadingOverlay.innerHTML = `
        <div class="loading-spinner"></div>
        <div class="loading-text">Processing payment...</div>
      `;
      formWrapper.appendChild(loadingOverlay);

      try {
        // Initialize Stripe
        var stripe = Stripe(settings.stripeSubscription.publishableKey);

        // Create payment element
        var elements = stripe.elements({
          mode: 'payment',
          paymentMethodCreation: 'manual',
          currency: settings.stripeSubscription.currency || 'aud',
          amount: settings.stripeSubscription.amount || 0,
          appearance: {
            theme: 'stripe',
            variables: {
              colorPrimary: '#0d6efd',
              colorBackground: '#ffffff',
              colorText: '#32325d',
              colorDanger: '#dc3545',
              fontFamily: '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif',
              spacingUnit: '4px',
              borderRadius: '4px'
            }
          }
        });

        // Create the payment element
        var paymentElement = elements.create('payment', {
          layout: {
            type: 'tabs',
            defaultCollapsed: false
          }
        });

        // Mount the payment element
        var paymentElementContainer = document.querySelector('#payment-element');
        if (paymentElementContainer) {
          paymentElement.mount(paymentElementContainer);
        }

        // Handle coupon application
        var couponInput = document.getElementById('coupon-code');
        var couponMessage = document.getElementById('coupon-message');
        var couponButton = document.getElementById('apply-coupon');
        var appliedPromotionCode = null;

        if (couponButton) {
          couponButton.addEventListener('click', async function(e) {
            e.preventDefault();
            
            var couponCode = couponInput.value.trim();
            if (!couponCode) return;

            couponButton.disabled = true;
            couponMessage.textContent = Drupal.t('Validating promotion code...');
            couponMessage.className = 'coupon-message';

            try {
              var response = await fetch('/subscription/validate-coupon', {
                method: 'POST',
                headers: {
                  'Content-Type': 'application/json',
                  'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({ coupon: couponCode })
              });

              var result = await response.json();

              if (result.valid) {
                appliedPromotionCode = result.promotion_code_id;
                couponMessage.textContent = Drupal.t('Promotion code applied: @description', 
                  {'@description': result.description}
                );
                couponMessage.className = 'coupon-message success';
                couponInput.disabled = true;
                couponButton.textContent = Drupal.t('Applied');
                
                // Calculate new GST and total
                const subtotal = parseFloat(result.final_amount);
                const gst = subtotal / 10; // 10% GST
                const total = subtotal + gst;

                // Update payment summary with new amounts
                updatePaymentSummary(
                  subtotal.toFixed(2),
                  gst.toFixed(2),
                  total.toFixed(2)
                );
              } else {
                couponMessage.textContent = result.message || Drupal.t('Invalid promotion code');
                couponMessage.className = 'coupon-message error';
                couponButton.disabled = false;
              }
            } catch (error) {
              couponMessage.textContent = Drupal.t('Error validating promotion code');
              couponMessage.className = 'coupon-message error';
              couponButton.disabled = false;
            }
          });
        }

        // Handle form submission
        var form = document.getElementById('stripe-payment-form');
        var submitButton = document.getElementById('stripe-submit');
        var errorElement = document.getElementById('payment-errors');

        if (form) {
          form.addEventListener('submit', async function(event) {
            event.preventDefault();
            submitButton.disabled = true;
            errorElement.style.display = 'none';
            toggleLoading(true);

            try {
              toggleLoading(true, 'Validating payment details...');
              var submitResult = await elements.submit();
              if (submitResult.error) {
                throw submitResult.error;
              }

              toggleLoading(true, 'Creating payment method...');
              var paymentResult = await stripe.createPaymentMethod({
                elements: elements,
                params: {
                  billing_details: {
                    email: settings.stripeSubscription.userEmail || '',
                    address: {
                      country: 'AU'
                    }
                  }
                }
              });

              if (paymentResult.error) {
                throw paymentResult.error;
              }

              toggleLoading(true, 'Processing payment...');
              var processResponse = await fetch(settings.stripeSubscription.processUrl, {
                method: 'POST',
                headers: {
                  'Content-Type': 'application/json',
                  'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({
                  payment_method_id: paymentResult.paymentMethod.id,
                  promotion_code: appliedPromotionCode
                })
              });

              var processResult = await processResponse.json();

              if (processResult.status === 'success') {
                toggleLoading(true, 'Payment successful! Redirecting...');
                window.location.href = processResult.redirect_url;
              } else if (processResult.status === 'requires_action') {
                toggleLoading(true, 'Additional verification required...');
                var confirmResult = await stripe.confirmPayment({
                  clientSecret: processResult.client_secret,
                  confirmParams: {
                    return_url: window.location.origin + '/mydashboard',
                  }
                });

                if (confirmResult.error) {
                  throw confirmResult.error;
                }
              } else {
                throw new Error(processResult.message || Drupal.t('Payment failed'));
              }
            } catch (error) {
              toggleLoading(false);
              errorElement.textContent = error.message;
              errorElement.style.display = 'block';
              submitButton.disabled = false;
            }
          });
        }

      } catch (error) {
        console.error('Stripe initialization error:', error);
        if (formWrapper) {
          formWrapper.innerHTML = '<div class="messages messages--error">' + 
            Drupal.t('Payment form is currently unavailable. Please try again later.') + 
            '</div>';
        }
      }
    }
  };
})(jQuery, Drupal, drupalSettings);