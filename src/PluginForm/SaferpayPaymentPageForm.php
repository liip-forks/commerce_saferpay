<?php

namespace Drupal\commerce_saferpay\PluginForm;

use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\Client;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a checkout form for the Datatrans gateway.
 */
class SaferpayPaymentPageForm extends PaymentOffsiteForm implements ContainerInjectionInterface {

  /**
   * The http client service.
   *
   * @var \GuzzleHttp\Client
   */
  protected $httpClient;

  /**
   * The log service.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * SaferpayPaymentPageForm constructor.
   *
   * @param \GuzzleHttp\Client $http_client
   *   The http client.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(Client $http_client, LoggerChannelFactoryInterface $logger_factory) {
    $this->httpClient = $http_client;
    $this->logger = $logger_factory->get('commerce_saferpay');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('http_client'),
      $container->get('logger.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $payment = $this->getEntity();
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $payment->getOrder();

    // Prevent orders that were already payed to go through the process again.
    // @todo Find out why we get sometimes redireced here instead of to the
    // return path.
    if ($order->isPaid()) {
      $this->logger->error('Order %order_id was already payed, no need to get back to the redirect form.', ['%order_id' => $order->id()]);
      throw new \UnexpectedValueException('Attempting to pay an already payed order.');
    }

    // @todo Save more stuff here?
    $payment = $this->getEntity();
    $return_urls = [
      'Success' => $form['#return_url'],
      'Fail' => $form['#return_url'],
      'Abort' => $form['#cancel_url'],
    ];
    $saferpay_response = $payment->getPaymentGateway()->getPlugin()->paymentPageInitialize($payment, $return_urls);
    $order->setData('commerce_saferpay', [
      'token' => $saferpay_response->Token,
    ])->save();

    return $this->buildRedirectForm($form, $form_state, $saferpay_response->RedirectUrl, [], static::REDIRECT_GET);
  }

}
