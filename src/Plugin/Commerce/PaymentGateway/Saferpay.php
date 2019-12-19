<?php

namespace Drupal\commerce_saferpay\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Url;
use Drupal\Core\Utility\Token;
use GuzzleHttp\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Provides the Saferpay payment gateway.
 *
 * @todo Add credit_card_types.
 *
 * @CommercePaymentGateway(
 *   id = "saferpay_paymentpage",
 *   label = "Saferpay PaymentPage",
 *   display_label = "Saferpay PaymentPage",
 *   forms = {
 *     "offsite-payment" = "Drupal\commerce_saferpay\PluginForm\SaferpayPaymentPageForm",
 *   },
 *   payment_method_types = {"credit_card"},
 * )
 */
class Saferpay extends OffsitePaymentGatewayBase {

  /**
   * The supported api version.
   */
  const API_VERSION = '1.10';

  /**
   * Production api url.
   */
  const API_URL_PROD = 'https://www.saferpay.com/api';

  /**
   * Test api url.
   */
  const API_URL_TEST = 'https://test.saferpay.com/api';

  /**
   * Saferpay captured status.
   */
  const SAFERPAY_CAPTURED = 'CAPTURED';

  /**
   * Saferpay authorised status.
   */
  const SAFERPAY_AUTHORIZED = 'AUTHORIZED';

  /**
   * Logger.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * Module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The http client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * Token service.
   *
   * @var \Drupal\Core\Utility\Token
   */
  protected $token;

  /**
   * Lock service.
   *
   * @var \Drupal\Core\Lock\LockBackendInterface
   */
  protected $lock;

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    /** @var \Drupal\commerce_saferpay\Plugin\Commerce\PaymentGateway\Saferpay $saferpay */
    $saferpay = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $saferpay->setLogger($container->get('logger.factory')->get('commerce_saferpay'))
      ->setModuleHandler($container->get('module_handler'))
      ->setHttpClient($container->get('http_client'))
      ->setToken($container->get('token'))
      ->setLock($container->get('lock'));
    $saferpay->languageManager = $container->get('language_manager');
    return $saferpay;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'customer_id' => '',
      'terminal_id' => '',
      'username' => '',
      'password' => '',
      'order_identifier' => '',
      'order_description' => '',
      'autocomplete' => TRUE,
      'debug' => FALSE,
      'request_alias' => FALSE,
      'payment_methods' => [],
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['customer_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Customer ID'),
      '#description' => t('You can get it from the Saferpay backoffice (Settings > Terminals).'),
      '#default_value' => $this->configuration['customer_id'],
      '#required' => TRUE,
    ];

    $form['terminal_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Terminal ID'),
      '#description' => $this->t('The number also listed as Contract number in Saferpay backoffice (Settings > Terminal).'),
      '#default_value' => $this->configuration['terminal_id'],
      '#required' => TRUE,
    ];

    $form['basic_auth'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Basic authentication settings'),
    ];

    $form['basic_auth']['username'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Username'),
      '#description' => $this->t('The username for the basic authentication (Settings > JSON API basic authentication).'),
      '#default_value' => $this->configuration['username'],
      '#required' => TRUE,
    ];

    $form['basic_auth']['password'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Password'),
      '#description' => $this->t('The password for the basic authentication (Settings > JSON API basic authentication).'),
      '#default_value' => $this->configuration['password'],
      '#required' => TRUE,
    ];

    $form['payment_methods'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Allowed payment methods'),
      '#description' => $this->t('Selecting none means all methods are allowed.'),
      '#default_value' => $this->configuration['payment_methods'],
      '#options' => $this->getSaferpayPaymentMethods(),
    ];

    $form['order_identifier'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Order identifier'),
      '#description' => $this->t('The order identifier sent to Saferpay Gateway (install token module to see available tokens).'),
      '#default_value' => $this->configuration['order_identifier'],
      '#required' => TRUE,
    ];

    $form['order_description'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Order description'),
      '#description' => $this->t('The order description displayed on the payment page (install token module to see available tokens.'),
      '#default_value' => $this->configuration['order_description'],
      '#required' => TRUE,
    ];

    $form['autocomplete'] = array(
      '#type' => 'checkbox',
      '#title' => $this->t('Auto Finalize payment by capture of transaction.'),
      '#default_value' => $this->configuration['autocomplete'],
    );

    $form['request_alias'] = array(
      '#type' => 'checkbox',
      '#title' => $this->t('Request alias'),
      '#description' => $this->t('To be able to use this setting, Saferpay support must set this up for the configured account. <strong>Note</strong>: This will request an alias and make it available to third party code, but it will not create reusable payments yet.'),
      '#default_value' => $this->configuration['request_alias'],
    );

    $form['debug'] = array(
      '#type' => 'checkbox',
      '#title' => $this->t('Output more verbose debug log.'),
      '#default_value' => $this->configuration['debug'],
    );

    if ($this->moduleHandler->moduleExists('token')) {
      $form['token_help'] = [
        '#theme' => 'token_tree_link',
        '#token_types' => ['commerce_order'],
      ];
      $form['order_identifier']['#description'] = $this->t('The order identifier sent to Saferpay Gateway (tokens can be used).');
      $form['order_description']['#description'] = $this->t('The order description sent to Saferpay Gateway (tokens can be used).');
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    $values = $form_state->getValue($form['#parents']);

    $this->configuration['customer_id'] = $values['customer_id'];
    $this->configuration['terminal_id'] = $values['terminal_id'];

    $this->configuration['username'] = $values['basic_auth']['username'];
    $this->configuration['password'] = $values['basic_auth']['password'];

    $this->configuration['order_identifier'] = $values['order_identifier'];
    $this->configuration['order_description'] = $values['order_description'];
    $this->configuration['autocomplete'] = $values['autocomplete'];
    $this->configuration['debug'] = $values['debug'];
    $this->configuration['request_alias'] = $values['request_alias'];
    $this->configuration['payment_methods'] = array_filter($values['payment_methods']);
  }

  /**
   * {@inheritdoc}
   */
  public function onReturn(OrderInterface $order, Request $request) {
    // We use the lock waiting mechanism here, because otherwise when
    // onNotify .
    $lid = 'commerce_saferpay_process_payment_' . $order->uuid();
    if (!$this->lock->lockMayBeAvailable($lid)) {
      $this->lock->wait($lid);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function onNotify(Request $request) {
    $query = $request->query->all();

    if (empty($query['order'])) {
      return new Response('Missing order query parameter.', 400);
    }

    $lid = 'commerce_saferpay_process_payment_' . $query['order'];
    if ($this->lock->acquire($lid)) {

      $orders = $this->entityTypeManager->getStorage('commerce_order')->loadByProperties(['uuid' => $query['order']]);
      $order = array_shift($orders);

      if (!$order instanceof OrderInterface) {
        $this->lock->release($lid);
        return new Response('Invalid order id.', 400);
      }

      $payment_process_result = $this->processPayment($order);

      if (!$payment_process_result) {
        $this->lock->release($lid);
        return new Response('Error while processing payment.', 400);
      }
    }

    $this->lock->release($lid);
    return new Response('OK', 200);
  }

  /**
   * Payment processing.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order object.
   *
   * @return bool|\Drupal\commerce_payment\Entity\PaymentInterface
   *   The saved payment or FALSE if something went wrong.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  protected function processPayment(OrderInterface $order) {
    $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');

    // Check if the payment has been processed.
    $payments = $payment_storage->getQuery()
      ->condition('payment_gateway', $this->entityId)
      ->condition('order_id', $order->id())
      ->execute();

    // Exit if the payment has been processed.
    // @todo Should we deal with partial payments?
    if (count($payments) > 0) {
      $this->logger->notice('Ignoring attempt to pay the already paid order %order_id.', [
        '%order_id' => $order->id(),
      ]);
      return FALSE;
    }

    // First assert and get the token.
    $assert_result = $this->paymentPageAssert($order);

    // @todo Log/store more stuff?
    $order_data = $order->getData('commerce_saferpay');
    $order_data['transaction_id'] = $assert_result->Transaction->Id;
    $order->setData('commerce_saferpay', $order_data)->save();

    // If authorized, capture.
    if ($assert_result->Transaction->Status != static::SAFERPAY_AUTHORIZED) {
      $this->logger->notice('Payment asserting for order %order_id failed. Saferpay status was %status. Saferpay transaction id was %transaction_id.', [
        '%order_id' => $order->id(),
        '%status' => $assert_result->Transaction->Status,
        '%transaction_id' => $assert_result->Transaction->Id,
      ]);
      return FALSE;
    }
    $payment_values = [
      'state' => 'authorization',
      'amount' => $order->getTotalPrice(),
      'payment_gateway' => $this->entityId,
      'order_id' => $order->id(),
      'test' => $this->getMode() == 'test',
      'remote_id' => $assert_result->Transaction->Id,
      'remote_state' => $assert_result->Transaction->Status,
      'authorized' => $this->time->getRequestTime(),
    ];

    if ($this->configuration['autocomplete']) {
      $capture_result = $this->transactionCapture($order);
      if ($capture_result->Status != static::SAFERPAY_CAPTURED) {
        $this->logger->notice('Payment capture for order %order_id failed. Saferpay status was %status.', [
          '%order_id' => $order->id(),
          '%status' => $capture_result->Status,
        ]);
        return FALSE;
      }
      $payment_values['remote_state'] = $capture_result->Status;
      $payment_values['state'] = 'completed';
    }

    $payment = $payment_storage->create($payment_values);
    \Drupal::moduleHandler()->invokeAll('commerce_saferpay_assert_result', [$assert_result, $order, $payment]);

    $payment->save();

    // @todo Create payment method when supported.
    //   https://www.drupal.org/project/commerce/issues/2838380.

    return $payment;
  }

  /**
   * Initialize the payment page.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentInterface $payment
   *   The payment object.
   * @param array $return_urls
   *   Return/fail/abort urls as expected by the api. See
   *   \Drupal\commerce_saferpay\PluginForm\SaferpayPaymentPageForm::buildConfigurationForm
   *   for the structure.
   *
   * @return \stdClass
   *   The converted json response.
   */
  public function paymentPageInitialize(PaymentInterface $payment, array $return_urls) {
    $order = $payment->getOrder();
    $currency_code = $payment->getAmount()->getCurrencyCode();
    $amount = $this->toMinorUnits($order->getTotalPrice());

    $data = [
      'TerminalId' => $this->configuration['terminal_id'],
      'Payment' => [
        'Amount' => [
          'Value' => $amount,
          'CurrencyCode' => $currency_code,
        ],
        'OrderId' => $this->token->replace($this->configuration['order_identifier'], ['commerce_order' => $order]),
        'Description' => $this->token->replace($this->configuration['order_description'], ['commerce_order' => $order]),
      ],
      'Notification' => [
        'NotifyUrl' => Url::fromRoute('commerce_payment.notify', ['commerce_payment_gateway' => $this->entityId], [
          'absolute' => TRUE,
          'query' => ['order' => $order->uuid()],
        ])->toString(),
      ],
    ];
    $data['ReturnUrls'] = $return_urls;

    if (!empty($this->configuration['request_alias'])) {
      $data['RegisterAlias']['IdGenerator'] = 'RANDOM';
    }

    $langcode = \Drupal::languageManager()->getCurrentLanguage()->getId();
    if (in_array($langcode, $this->getSaferpayLanguages())) {
      $data['Payer']['LanguageCode'] = $langcode;
    }

    if ($this->configuration['payment_methods']) {
      $data['PaymentMethods'] = array_values($this->configuration['payment_methods']);
    }

    $saferpay_response = $this->doRequest('/Payment/v1/PaymentPage/Initialize', $order->uuid(), $data);

    if ($this->configuration['debug']) {
      $this->logger->info('PaymentPage initialized. Request id (order uuid): %request_id. Token: %token. Expires: %expires.', [
        '%request_id' => $order->uuid(),
        '%token' => $saferpay_response->Token,
        '%expires' => $saferpay_response->Expiration,
      ]);
    }

    return $saferpay_response;
  }

  /**
   * PaymentPage Assert api call.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order object.
   *
   * @return \stdClass
   *   The result of the call.
   */
  public function paymentPageAssert(OrderInterface $order) {
    $order_data = $order->getData('commerce_saferpay');
    $saferpay_response = $this->doRequest('/Payment/v1/PaymentPage/Assert', $order->uuid(), ['Token' => $order_data['token']]);

    if ($this->configuration['debug']) {
      $this->logger->info('PaymentPage assert call finished. Request id (order uuid): %request_id. Order: %order_id. Status: %status. Transaction id: %transaction_id.', [
        '%request_id' => $order->uuid(),
        '%order_id' => $order->id(),
        '%status' => $saferpay_response->Transaction->Status,
        '%transaction_id' => $saferpay_response->Transaction->Id,
      ]);
    }

    return $saferpay_response;
  }

  /**
   * Transaction capture call.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order object.
   *
   * @return \stdClass
   *   The result of the call.
   */
  public function transactionCapture(OrderInterface $order) {
    $order_data = $order->getData('commerce_saferpay');
    $data = [
      'TransactionReference' => [
        'TransactionId' => $order_data['transaction_id'],
      ],
    ];

    $saferpay_response = $this->doRequest('/Payment/v1/Transaction/Capture', $order->uuid(), $data);

    if ($this->configuration['debug']) {
      $this->logger->info('Transaction capture call finished. Request id (order uuid): %request_id. Order: %order_id. Status: %status.', [
        '%request_id' => $order->uuid(),
        '%order_id' => $order->id(),
        '%status' => $saferpay_response->Status,
      ]);
    }

    return $saferpay_response;
  }

  /**
   * Does a post request using defaults parameters.
   *
   * @param string $url
   *   The url for the request. Ie: /Payment/v1/Transaction/Capture.
   * @param string $request_id
   *   The unique identifier for this set of transactions.
   * @param array $data
   *   The payload data.
   *
   * @return \stdClass
   *   The response data.
   */
  public function doRequest($url, $request_id, array $data = []) {
    $headers = [
      'Content-Type' => 'application/json; charset=utf-8',
      'Accept' => 'application/json',
    ];

    $data['RequestHeader'] = [
      'SpecVersion' => static::API_VERSION,
      'CustomerId' => $this->configuration['customer_id'],
      'RequestId' => $request_id,
      'RetryIndicator' => 0,
    ];

    $url = (($this->configuration['mode'] === 'live') ? static::API_URL_PROD : static::API_URL_TEST) . $url;
    try {
      $response = $this->httpClient->post($url, [
        'headers' => $headers,
        'json' => $data,
        'auth' => [$this->configuration['username'], $this->configuration['password']],
      ]);
    }
    catch (\Exception $e) {
      $log[] = "Exception: {$e->getMessage()}.";

      // @see https://saferpay.github.io/jsonapi/#errorhandling
      $error_response = $e->getResponse()->getBody()->getContents();
      if (!empty($error_response)) {
        $error_response_content = json_decode($error_response);
        $log[] = "Error name: {$error_response_content->ErrorName}";
        $log[] = "Error message: {$error_response_content->ErrorMessage}";
      }

      throw new PaymentGatewayException(implode(' / ', $log));
    }

    return json_decode($response->getBody()->getContents());
  }

  /**
   * Set Logger.
   *
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The logger service.
   *
   * @return Saferpay
   *   This object.
   */
  public function setLogger(LoggerChannelInterface $logger) {
    $this->logger = $logger;
    return $this;
  }

  /**
   * Set the module handler.
   *
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   *
   * @return Saferpay
   *   This object.
   */
  public function setModuleHandler(ModuleHandlerInterface $module_handler) {
    $this->moduleHandler = $module_handler;
    return $this;
  }

  /**
   * Set the http client.
   *
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The http client.
   *
   * @return Saferpay
   *   This object.
   */
  public function setHttpClient(ClientInterface $http_client) {
    $this->httpClient = $http_client;
    return $this;
  }

  /**
   * Set the token service.
   *
   * @param \Drupal\Core\Utility\Token $token
   *   The token service.
   *
   * @return Saferpay
   *   This object.
   */
  public function setToken(Token $token) {
    $this->token = $token;
    return $this;
  }

  /**
   * Set the lock service.
   *
   * @param \Drupal\Core\Lock\LockBackendInterface $lock
   *   The lock service.
   *
   * @return Saferpay
   *   This object.
   */
  public function setLock(LockBackendInterface $lock) {
    $this->lock = $lock;
    return $this;
  }

  /**
   * Returns a list of language codes supported by Saferpay.
   *
   * @return string[]
   */
  protected function getSaferpayLanguages() {
    return [
      'de',
      'en',
      'fr',
      'da',
      'cs',
      'es',
      'hr',
      'it',
      'hu',
      'nl',
      'no',
      'pl',
      'pt',
      'ru',
      'ro',
      'sk',
      'sl',
      'fi',
      'sv',
      'tr',
      'el',
      'ja',
      'zh',
    ];
  }

  /**
   * Returns the saferpay payment types.
   *
   * @return string[]
   *   Payment method descriptions keyed by identifier.
   */
  protected function getSaferpayPaymentMethods() {
    return [
      'ALIPAY' => $this->t('Alipay'),
      'AMEX' => $this->t('American Express'),
      'BANCONTACT' => $this->t('Bancontact'),
      'BONUS' => $this->t('Bonus Card'),
      'DINERS' => $this->t('Diners Club'),
      'DIRECTDEBIT' => $this->t('BillPay Direct Debit'),
      'EPRZELEWY' => $this->t('ePrzelewy'),
      'EPS' => $this->t('eps'),
      'GIROPAY' => $this->t('giropay'),
      'IDEAL' => $this->t('iDEAL'),
      'INVOICE' => $this->t('Invoice'),
      'JCB' => $this->t('JCB'),
      'MAESTRO' => $this->t('Maestro Int.'),
      'MASTERCARD' => $this->t('Mastercard'),
      'MYONE' => $this->t('MyOne'),
      'PAYPAL' => $this->t('PayPal'),
      'PAYDIREKT' => $this->t('paydirekt'),
      'POSTCARD' => $this->t('Postfinance Card'),
      'POSTFINANCE' => $this->t('Postfinance eFinance'),
      'SAFERPAYTEST' => $this->t('Saferpay Test'),
      'SOFORT' => $this->t('SOFORT'),
      'TWINT' => $this->t('TWINT'),
      'UNIONPAY' => $this->t('Unionpay'),
      'VISA' => $this->t('VISA'),
      'VPAY' => $this->t('VPay'),
    ];
  }

}
