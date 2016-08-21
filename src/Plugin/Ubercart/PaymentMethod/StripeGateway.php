<?php

namespace Drupal\uc_stripe\Plugin\Ubercart\PaymentMethod;

use Drupal\Core\Form\FormStateInterface;
use Drupal\uc_credit\CreditCardPaymentMethodBase;
use Drupal\uc_order\OrderInterface;

/**
 * Stripe Ubercart gateway payment method.
 *
 *
 * @UbercartPaymentMethod(
 *   id = "stripe_gateway",
 *   name = @Translation("Stripe gateway"),
 * )
 */
class StripeGateway extends CreditCardPaymentMethodBase {


  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['uc_stripe_api_key_test_secret'] = array(
      '#type' => 'textfield',
      '#title' => t('Test Secret Key'),
      '#default_value' => $this->configuration['uc_stripe_api_key_test_secret'],
      '#description' => t('Your Development Stripe API Key. Must be the "secret" key, not the "publishable" one.'),
    );

    $form['uc_stripe_api_key_test_publishable'] = array(
      '#type' => 'textfield',
      '#title' => t('Test Publishable Key'),
      '#default_value' => $this->configuration['uc_stripe_api_key_test_publishable'],
      '#description' => t('Your Development Stripe API Key. Must be the "publishable" key, not the "secret" one.'),
    );

    $form['uc_stripe_api_key_live_secret'] = array(
      '#type' => 'textfield',
      '#title' => t('Live Secret Key'),
      '#default_value' => $this->configuration['uc_stripe_api_key_live_secret'],
      '#description' => t('Your Live Stripe API Key. Must be the "secret" key, not the "publishable" one.'),
    );

    $form['uc_stripe_api_key_live_publishable'] = array(
      '#type' => 'textfield',
      '#title' => t('Live Publishable Key'),
      '#default_value' => $this->configuration['uc_stripe_api_key_live_publishable'],
      '#description' => t('Your Live Stripe API Key. Must be the "publishable" key, not the "secret" one.'),
    );

    $form['uc_stripe_testmode'] = array(
      '#type' => 'checkbox',
      '#title' => t('Test mode'),
      '#description' => 'Testing Mode: Stripe will use the development API key to process the transaction so the card will not actually be charged.',
      '#default_value' => $this->configuration['uc_stripe_testmode'],
    );

    $form['uc_stripe_poweredby'] = array(
      '#type' => 'checkbox',
      '#title' => t('Powered by Stripe'),
      '#description' => 'Show "powered by Stripe" in shopping cart.',
      '#default_value' => $this->configuration['uc_stripe_poweredby'],
    );

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    foreach (['uc_stripe_api_key_test_secret', 'uc_stripe_api_key_test_publishable', 'uc_stripe_api_key_live_secret', 'uc_stripe_api_key_live_publishable', 'uc_stripe_testmode', 'uc_stripe_poweredby'] as $item) {
      $this->configuration[$item] = $form_state->getValue($item);
    }

    return parent::submitConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  protected function chargeCard(OrderInterface $order, $amount, $txn_type, $reference = NULL) {
    $user = \Drupal::currentUser();

    if (!$this->prepareApi()) {
      $result = array(
        'success' => FALSE,
        'comment' => t('Stripe API not found.'),
        'message' => t('Stripe API not found. Contact the site administrator.'),
        'uid' => $user->id(),
        'order_id' => $order->id(),
      );
      return $result;
    }

    // Format the amount in cents, which is what Stripe wants
    $amount = uc_currency_format($amount, FALSE, FALSE, FALSE);

    $stripe_customer_id = FALSE;

    // If the user running the order is not the order's owner
    // (like if an admin is processing an order on someone's behalf)
    // then load the customer ID from the user object.
    // Otherwise, make a brand new customer each time a user checks out.
    if ($user->id() != $order->id()) {
      $stripe_customer_id = _uc_stripe_get_customer_id($order->id());
    }


    // Always Create a new customer in stripe for new orders

    if (!$stripe_customer_id) {

      try {
        // If the token is not in the user's session, we can't set up a new customer
        $stripe_token = \Drupal::service('user.private_tempstore')->get('uc_stripe')->get('uc_stripe_token');

        if (empty($stripe_token)) {
          throw new \Exception('Token not found');
        }

        //Create the customer in stripe
        $customer = \Stripe\Customer::create(array(
            "source" => $stripe_token,
            'description' => "OrderID: {$order->id()}",
            'email' => $order->getEmail(),
          )
        );

        // Store the customer ID in temp storage,
        // We'll pick it up later to save it in the database since we might not have a $user object at this point anyway
        \Drupal::service('user.private_tempstore')->get('uc_stripe')->set('uc_stripe_customer_id', $customer->id());

      } catch (Exception $e) {
        $result = array(
          'success' => FALSE,
          'comment' => $e->getCode(),
          'message' => t("Stripe Customer Creation Failed for order !order: !message", array(
            "!order" => $order->id(),
            "!message" => $e->getMessage()
          )),
          'uid' => $user->id(),
          'order_id' => $order->id(),
        );

        uc_order_comment_save($order->id(), $user->id(), $result['message']);

        \Drupal::logger('uc_stripe')
          ->notice('Failed stripe customer creation: @message', array('@message' => $result['message']));
        $message = $this->t('Credit card charge failed.');
        uc_order_comment_save($order->id(), $user->id(), $message, 'admin');
        return $result;
      }

      $message = $this->t('Credit card charged: @amount', ['@amount' => uc_currency_format($amount)]);
      uc_order_comment_save($order->id(), $user->id(), $message, 'admin');

      $result = array(
        'success' => TRUE,
        'comment' => $this->t('Card charged, resolution code: 0022548315'),
        'message' => $this->t('Credit card payment processed successfully.'),
        'uid' => $user->id(),
      );

      return $result;
    }
  }


  public function cartDetails(OrderInterface $order, array $form, FormStateInterface $form_state) {
    $details = parent::cartDetails($order, $form, $form_state);

//    $output = \Drupal::service("renderer")->render($form['stripe_nojs_warning']);
//    $output .= \Drupal::service("renderer")->render($form['config_error']);
//
//    $output .= \Drupal::service("renderer")->render($form['stripe_token']);
//    $output .= \Drupal::service("renderer")->render($form['dummy_image_load']);

    return $details;

  }


  /**
   * Load stripe API
   *
   * @return bool
   */
  public function prepareApi() {

    if (!$this->checkApiKeys()) {
      \Drupal::logger('uc_stripe')->error('Stripe API keys are not configured. Payments cannot be made without them.', array());
      return FALSE;
    }

    $secret_key = $this->configuration['uc_stripe_testmode'] ? $this->configuration['uc_stripe_api_key_test_secret'] : $this->configuration['uc_stripe_api_key_live_secret'];
    try {
      \Stripe\Stripe::setApiKey($secret_key);
    } catch (Exception $e) {
      \Drupal::logger('uc_stripe')->notice('Error setting the Stripe API Key. Payments will not be processed: %error', array('%error' => $e->getMessage()));
    }
    return TRUE;
  }

  /**
   * Check that all API keys are configured.
   *
   * @return bool
   *   TRUE if all 4 keys have a value.
   */
  public function checkApiKeys() {

    return ($this->configuration['uc_stripe_api_key_live_publishable'] &&
      $this->configuration['uc_stripe_api_key_live_secret'] &&
      $this->configuration['uc_stripe_api_key_test_publishable'] &&
      $this->configuration['uc_stripe_api_key_test_secret']);
  }
}
