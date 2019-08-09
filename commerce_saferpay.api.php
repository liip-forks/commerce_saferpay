<?php

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;

/**
 * @file
 * API documentation of the Commerce Saferpay module.
 */

/**
 * Allows modules to act on the returned saferpay assert data.
 *
 * @param object $assert_result
 *   The data returned by Saferpay.
 * @param \Drupal\commerce_order\Entity\OrderInterface $order
 *   The order entity.
 * @param \Drupal\commerce_payment\Entity\PaymentInterface $payment
 *   The payment entity.
 */
function hook_commerce_saferpay_assert_result($assert_result, OrderInterface $order, PaymentInterface $payment) {
  if (!empty($assert_result->RegistrationResult->Alias->Id)) {
    // Do something with the stored credit card alias.
  }
}
