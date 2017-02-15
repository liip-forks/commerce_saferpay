<?php

/**
 * @file
 * Saferpay menu items callbacks.
 */

/**
 * Page callback for saferpay notify URL callback.
 */
function commerce_saferpay_pp_notify($payment_method_instance, $order) {
  $data = $_POST['DATA'];
  $signature = $_POST['SIGNATURE'];

  while (drupal_strtolower(drupal_substr($data, 0, 14)) == '<idp msgtype=\\') {
    $data = stripslashes($data);
  }

  if (!commerce_saferpay_confirm_validate($data, $signature, $payment_method_instance['settings'])) {
    drupal_access_denied();
    return;
  }

  if (commerce_saferpay_pp_process_data($order, $payment_method_instance, $data)) {
    commerce_payment_redirect_pane_next_page($order, t('Triggered by Saferpay notify'));
  }
}

