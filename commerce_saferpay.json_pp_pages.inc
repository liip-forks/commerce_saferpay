<?php

/**
 * @file
 * Saferpay json pp api notify callback.
 */

/**
 * Page callback for saferpay notify URL callback.
 */
function commerce_saferpay_json_pp_notify($payment_method, $order) {
  if (lock_acquire(__METHOD__)) {

    // First check if this was already processed.
    $balance_result = commerce_payment_order_balance($order);
    if ($balance_result && $balance_result['amount'] == 0) {
      // This was already paid. We can exit.
      return FALSE;
    }

    // PP assert.
    $assert_result = commerce_saferpay_json_pp_assert($order, $payment_method);
    if ($assert_result['Transaction']['Status'] != SAFERPAY_AUTHORIZED) {
      watchdog('commerce_saferpay_json_pp','Payment asserting for order %order_id failed. Saferpay status was %status. Saferpay transaction id was %transaction_id.', [
        '%order_id' => $order->order_id,
        '%status' => $assert_result['Transaction']['Status'],
        '%transaction_id' => $assert_result['Transaction']['Id'],
      ]);

      return FALSE;
    }

    $transaction = commerce_saferpay_get_transaction($order, $assert_result['Transaction']['Id'], $payment_method['method_id']);
    // Do not process the same transaction twice.
    if (empty($transaction->is_new)) {
      return FALSE;
    }

    $transaction->remote_id = $assert_result['Transaction']['Id'];
    $transaction->amount = $assert_result['Transaction']['Amount']['Value'];
    $transaction->currency_code = $assert_result['Transaction']['Amount']['CurrencyCode'];
    $transaction->payload['assert_result'] = $assert_result;

    $transaction->status = COMMERCE_PAYMENT_STATUS_PENDING;
    $transaction->message = t('Transaction has been reserved.');

    // @todo Check if we need this default settings.
    // $settings = commerce_saferpay_apply_default_settings($payment_method['settings']);
    // PP capture.
    if (isset($payment_method['settings']['autocomplete']) && $payment_method['settings']['autocomplete']) {
      $capture_result = commerce_saferpay_json_pp_capture($order, $payment_method);

      if ($capture_result['Status'] == SAFERPAY_CAPTURED) {
        $transaction->status = COMMERCE_PAYMENT_STATUS_SUCCESS;
        $transaction->message = t('Successfully completed the payment.');
        commerce_payment_transaction_save($transaction);
      }
      else {
        $transaction->status = COMMERCE_PAYMENT_STATUS_FAILURE;
        $transaction->message = t('Failed to complete payment: @error', array('@error' => $capture_result));
      }
    }

    $cardonfile_exists = module_exists('commerce_cardonfile');
    if ($cardonfile_exists && $payment_method['settings']['request_card_id']) {

      if (!empty($assert_result['RegistrationResult']['Alias']['Id'])) {
        // @see commerce_payment_credit_card_types()
        $type_mapping = array(
          'visa' => 'VISA',
          'mastercard' => 'MASTERCARD',
          'dc' => 'DINERS',
          'jcb' => 'JCB',
          'maestro' => 'MAESTRO',
        );

        $saferpay_card_type = $assert_result['PaymentMeans']['Brand']['PaymentMethod'];
        $card_type = in_array($saferpay_card_type, $type_mapping) ? array_search($saferpay_card_type, $type_mapping) : '';

        $card_data = array(
          'uid' => $order->uid,
          'payment_method' => $payment_method['method_id'],
          'instance_id' => $payment_method['instance_id'],
          'remote_id' => $assert_result['RegistrationResult']['Alias']['Id'],
          'card_type' => $card_type,
          'card_name' => '',
          'card_number' => substr($assert_result['PaymentMeans']['Card']['MaskedNumber'], -4),
          'card_exp_month' => $assert_result['PaymentMeans']['Card']['ExpMonth'],
          'card_exp_year' => $assert_result['PaymentMeans']['Card']['ExpYear'],
          'status' => 1,
        );
        // Save new card on file.
        commerce_cardonfile_data_save($card_data);

        // Store the card information in order.
        $transaction->payload['cardonfile'] = $card_data['card_id'];
      }
      else {
        watchdog('commerce_saferpay', 'No alias received for transaction @id', array('@id' => $assert_result['Transaction']['Id']), WATCHDOG_ERROR);
      }
    }

    commerce_payment_transaction_save($transaction);

    lock_release(__METHOD__);
  }

}
