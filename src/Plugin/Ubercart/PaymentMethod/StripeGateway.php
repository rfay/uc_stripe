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
  public function defaultConfiguration() {
    return parent::defaultConfiguration() + [
      'debug' => FALSE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);
    $form['uc_stripe_settings'] = array(
      '#type' => 'fieldset',
      '#title' => t('Stripe settings'),
    );

    $form['uc_stripe_settings']['uc_stripe_api_key_test_secret'] = array(
      '#type' => 'textfield',
      '#title' => t('Test Secret Key'),
      '#default_value' => \Drupal::config('uc_stripe.settings')
        ->get('uc_stripe_api_key_test_secret'),
      '#description' => t('Your Development Stripe API Key. Must be the "secret" key, not the "publishable" one.'),
    );

    $form['uc_stripe_settings']['uc_stripe_api_key_test_publishable'] = array(
      '#type' => 'textfield',
      '#title' => t('Test Publishable Key'),
      '#default_value' => \Drupal::config('uc_stripe.settings')
        ->get('uc_stripe_api_key_test_publishable'),
      '#description' => t('Your Development Stripe API Key. Must be the "publishable" key, not the "secret" one.'),
    );

    $form['uc_stripe_settings']['uc_stripe_api_key_live_secret'] = array(
      '#type' => 'textfield',
      '#title' => t('Live Secret Key'),
      '#default_value' => \Drupal::config('uc_stripe.settings')
        ->get('uc_stripe_api_key_live_secret'),
      '#description' => t('Your Live Stripe API Key. Must be the "secret" key, not the "publishable" one.'),
    );

    $form['uc_stripe_settings']['uc_stripe_api_key_live_publishable'] = array(
      '#type' => 'textfield',
      '#title' => t('Live Publishable Key'),
      '#default_value' => \Drupal::config('uc_stripe.settings')
        ->get('uc_stripe_api_key_live_publishable'),
      '#description' => t('Your Live Stripe API Key. Must be the "publishable" key, not the "secret" one.'),
    );

    $form['uc_stripe_settings']['uc_stripe_testmode'] = array(
      '#type' => 'checkbox',
      '#title' => t('Test mode'),
      '#description' => 'Testing Mode: Stripe will use the development API key to process the transaction so the card will not actually be charged.',
      '#default_value' => \Drupal::config('uc_stripe.settings')
        ->get('uc_stripe_testmode'),
    );

    $form['uc_stripe_settings']['uc_stripe_poweredby'] = array(
      '#type' => 'checkbox',
      '#title' => t('Powered by Stripe'),
      '#description' => 'Show "powered by Stripe" in shopping cart.',
      '#default_value' => \Drupal::config('uc_stripe.settings')
        ->get('uc_stripe_poweredby'),
    );

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    return parent::submitConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  protected function chargeCard(OrderInterface $order, $amount, $txn_type, $reference = NULL) {
    $user = \Drupal::currentUser();

    // Format the amount in cents, which is what Stripe wants
    $amount = uc_currency_format($amount, FALSE, FALSE, FALSE);

    $stripe_customer_id = FALSE;

    // If the user running the order is not the order's owner
    // (like if an admin is processing an order on someone's behalf)
    // then load the customer ID from the user object.
    // Otherwise, make a brand new customer each time a user checks out.
    if ($user->uid != $order->uid) {
      $stripe_customer_id = _uc_stripe_get_customer_id($order->uid);
    }


    // Always Create a new customer in stripe for new orders

    if (!$stripe_customer_id) {

      try {
        // If the token is not in the user's session, we can't set up a new customer
        if (empty($_SESSION['stripe']['token'])) {
          throw new Exception('Token not found');
        }
        $stripe_token = $_SESSION['stripe']['token'];

        //Create the customer in stripe
        $customer = \Stripe\Customer::create(array(
            "source" => $stripe_token,
            'description' => "OrderID: {$order->order_id}",
            'email' => "$order->primary_email"
          )
        );

        // Store the customer ID in the session,
        // We'll pick it up later to save it in the database since we might not have a $user object at this point anyway
        $stripe_customer_id = $_SESSION['stripe']['customer_id'] = $customer->id;

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

        uc_order_comment_save($order->id(), $user->uid, $result['message'], 'admin');

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
}
