<?php

namespace Worldpay\Payments\Model\Methods;

use Magento\Framework\Api\AttributeValueFactory;
use Magento\Framework\Api\ExtensionAttributesFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Payment\Model\InfoInterface;
use Magento\Framework\Exception\LocalizedException;

class WorldpayPayments extends AbstractMethod
{
    protected $_isInitializeNeeded = true;
    protected $_isGateway = true;
    protected $_canAuthorize = true;
    protected $_canCapture = true;
    protected $_canCapturePartial = true;
    protected $_canVoid = true;
    protected $_canUseInternal = true;
    protected $_canUseCheckout = true;
    protected $_canRefund = true;
    protected $_canRefundInvoicePartial = true;
    protected $backendAuthSession;
    protected $cart;
    protected $urlBuilder;
    protected $_objectManager;
    protected $invoiceSender;
    protected $transactionFactory;
    protected $customerSession;
    protected $savedCardFactory;
    protected $checkoutSession;
    protected $checkoutData;
    protected $quoteRepository;
    protected $quoteManagement;
    protected $orderSender;
    protected $sessionQuote;

    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory,
        \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory,
        \Magento\Payment\Helper\Data $paymentData,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Payment\Model\Method\Logger $logger,
        \Magento\Backend\Model\Auth\Session $backendAuthSession,
        \Worldpay\Payments\Model\Config $config,
        \Magento\Checkout\Model\Cart $cart,
        \Magento\Framework\UrlInterface $urlBuilder,
        \Magento\Framework\ObjectManagerInterface $objectManager,
        \Magento\Sales\Model\Order\Email\Sender\InvoiceSender $invoiceSender,
        \Magento\Framework\DB\TransactionFactory $transactionFactory,
        \Magento\Customer\Model\Session $customerSession,
        \Worldpay\Payments\Model\Resource\SavedCard\CollectionFactory $savedCardFactory,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Checkout\Helper\Data $checkoutData,
        \Magento\Quote\Api\CartRepositoryInterface $quoteRepository,
        \Magento\Quote\Api\CartManagementInterface $quoteManagement,
        \Magento\Sales\Model\Order\Email\Sender\OrderSender $orderSender,
        \Magento\Backend\Model\Session\Quote $sessionQuote,
        \Worldpay\Payments\Logger\Logger $wpLogger,
        \Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        \Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger,
            $resource,
            $resourceCollection,
            $data
        );
        $this->urlBuilder = $urlBuilder;
        $this->backendAuthSession = $backendAuthSession;
        $this->config = $config;
        $this->cart = $cart;
        $this->_objectManager = $objectManager;
        $this->invoiceSender = $invoiceSender;
        $this->transactionFactory = $transactionFactory;
        $this->customerSession = $customerSession;
        $this->savedCardFactory = $savedCardFactory;
        $this->checkoutSession = $checkoutSession;
        $this->checkoutData = $checkoutData;
        $this->quoteRepository = $quoteRepository;
        $this->quoteManagement = $quoteManagement;
        $this->orderSender = $orderSender;
        $this->sessionQuote = $sessionQuote;
        $this->logger = $wpLogger;
    }

    public function initialize($paymentAction, $stateObject)
    {
        $stateObject->setState(\Magento\Sales\Model\Order::STATE_PENDING_PAYMENT);
        $stateObject->setStatus('pending_payment');
        $stateObject->setIsNotified(false);
    }

    public function getOrderPlaceRedirectUrl() {
        return $this->urlBuilder->getUrl('worldpay/apm/redirect', ['_secure' => true]);
    }

    public function assignData(\Magento\Framework\DataObject $data)
    {
        parent::assignData($data);

        $_tmpData = $data->_data;
        $_serializedAdditionalData = serialize($_tmpData['additional_data']);
        $additionalDataRef = $_serializedAdditionalData;
        $additionalDataRef = unserialize($additionalDataRef);
        $_paymentToken = $additionalDataRef['paymentToken'];

        $infoInstance = $this->getInfoInstance();
        $infoInstance->setAdditionalInformation('payment_token', $_paymentToken);
        return $this;
    }

    public function createApmOrder($quote) {


        $orderId = $quote->getReservedOrderId();
        $payment = $quote->getPayment();
        $token = $payment->getAdditionalInformation('payment_token');
        $amount = $quote->getGrandTotal();

        $worldpay = $this->setupWorldpay();

        $currency_code = $quote->getQuoteCurrencyCode();

        $orderDetails = $this->getSharedOrderDetails($quote, $currency_code);

        try {
            $createOrderRequest = [
                'token' => $token,
                'orderDescription' => $orderDetails['orderDescription'],
                'amount' => $amount*100,
                'currencyCode' => $orderDetails['currencyCode'],
                'siteCode' => $orderDetails['siteCode'],
                'name' => $orderDetails['name'],
                'billingAddress' => $orderDetails['billingAddress'],
                'deliveryAddress' => $orderDetails['deliveryAddress'],
                'customerOrderCode' => $orderId,
                'settlementCurrency' => $orderDetails['settlementCurrency'],
                'successUrl' => $this->urlBuilder->getUrl('worldpay/apm/success', ['_secure' => true]),
                'pendingUrl' =>$this->urlBuilder->getUrl('worldpay/apm/pending', ['_secure' => true]),
                'failureUrl' => $this->urlBuilder->getUrl('worldpay/apm/failure', ['_secure' => true]),
                'cancelUrl' => $this->urlBuilder->getUrl('worldpay/apm/cancel', ['_secure' => true]),
                'shopperIpAddress' => $orderDetails['shopperIpAddress'],
                'shopperSessionId' => $orderDetails['shopperSessionId'],
                'shopperUserAgent' => $orderDetails['shopperUserAgent'],
                'shopperAcceptHeader' => $orderDetails['shopperAcceptHeader'],
                'shopperEmailAddress' => $orderDetails['shopperEmailAddress']
            ];
            $this->_debug('Order Request: ' .  print_r($createOrderRequest, true));
            $response = $worldpay->createApmOrder($createOrderRequest);
            $this->_debug('Order Response: ' .  print_r($response, true));
            
            if ($response['paymentStatus'] === 'SUCCESS') {
                $this->_debug('Order Request: ' . $response['orderCode']  . ' SUCCESS');
                $payment->setIsTransactionClosed(false)
                    ->setTransactionId($response['orderCode'])
                    ->setShouldCloseParentTransaction(false);
                if ($payment->isCaptureFinal($amount)) {
                    $payment->setShouldCloseParentTransaction(true);
                }
            }
            else if ($response['paymentStatus'] == 'PRE_AUTHORIZED') {
               $this->_debug('Order Request: ' . $response['orderCode']  . ' PRE_AUTHORIZED');
                $payment->setAmount($amount);
                $payment->setAdditionalInformation("worldpayOrderCode", $response['orderCode']);
                $payment->setLastTransId($orderId);
                $payment->setTransactionId($response['orderCode']);
                $payment->setIsTransactionClosed(false);
                $payment->setCcTransId($response['orderCode']);
                $payment->save();
                return $response['redirectURL'];
            }
            else {
                if (isset($response['paymentStatusReason'])) {
                    throw new \Exception($response['paymentStatusReason']);
                } else {
                    throw new \Exception(print_r($response, true));
                }
            }
        }
        catch (\Exception $e) {

            $payment->setStatus(self::STATUS_ERROR);
            $payment->setAmount($amount);
            $payment->setLastTransId($orderId);
            $this->_debug($e->getMessage());
            throw new \Exception('Payment failed, please try again later ' . $e->getMessage());
        }
        return false;
    }

    public function isTokenAllowed()
    {
        return true;
    }

    public function capture(InfoInterface $payment, $amount)
    {
       return $this;
    }

    public function authorize(InfoInterface $payment, $amount)
    {
        return $this;
    }

    private function getSession()
    {
        $sessionValue = $this->checkoutSession->getWorldpay3DSSession();

        if (empty($sessionValue)) {
            $sessionValue = $this->checkoutSession->getSessionId();
        }

        return $sessionValue;
    }

    public function setupWorldpay() {
        $service_key = $this->config->getServiceKey();
        $worldpay = new \Worldpay\Worldpay($service_key);
        $worldpay->setPluginData('Magento2', '2.0.27');
        \Worldpay\Utils::setThreeDSShopperObject([
            'shopperIpAddress' => $this->stripPortNumberFromIp($this->getClientIp()),
            'shopperSessionId' => $this->getSession(),
            'shopperUserAgent' => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '',
            'shopperAcceptHeader' => '*/*'
        ]);
        return $worldpay;
    }

    public function refund(InfoInterface $payment, $amount)
    {
        if ($order = $payment->getOrder()) {
            $worldpay = $this->setupWorldpay();
            try {
                $grandTotal = $order->getGrandTotal();
                if ($grandTotal == $amount) {
                    $worldpay->refundOrder($payment->getAdditionalInformation("worldpayOrderCode"));
                } else {
                    $worldpay->refundOrder($payment->getAdditionalInformation("worldpayOrderCode"), $amount * 100);
                }
                return $this;
            }
            catch (\Exception $e) {
                 throw new LocalizedException(__('Refund failed ' . $e->getMessage()));
                throw new LocalizedException(__('Refund failed ' . $e->getMessage()));
            }
        }
    }

    public function void(InfoInterface $payment)
    {
        $worldpayOrderCode = $payment->getAdditionalInformation('worldpayOrderCode');
        $worldpay = $this->setupWorldpay();
        if ($worldpayOrderCode) {
            try {
                $worldpay->cancelAuthorizedOrder($worldpayOrderCode);
            }
            catch (\Exception $e) {
                throw new LocalizedException(__('Void failed, please try again later ' . $e->getMessage()));
            }
        }
        return true;
    }

    public function cancel(InfoInterface $payment)
    {
        throw new LocalizedException(__('You cannot cancel an APM order'));
    }

    public function updateOrder($status, $orderCode, $order, $payment, $amount) {

        if ($status === 'REFUNDED' || $status === 'SENT_FOR_REFUND') {
            $payment
            ->setTransactionId($orderCode)
            ->setParentTransactionId($orderCode)
            ->setIsTransactionClosed(true)
            ->registerRefundNotification($amount);

            $this->_debug('Order: ' .  $orderCode .' REFUNDED');
        }
        else if ($status === 'FAILED') {

            $order->cancel()->setState(\Magento\Sales\Model\Order::STATE_CANCELED, true, 'Gateway has declined the payment.')->save();
            $payment->setStatus(self::STATUS_DECLINED);

            $this->_debug('Order: ' .  $orderCode .' FAILED');
        }
        else if ($status === 'SETTLED') {
            $this->_debug('Order: ' .  $orderCode .' SETTLED');
        }
        else if ($status === 'AUTHORIZED') {
            $payment
                ->setTransactionId($orderCode)
                ->setShouldCloseParentTransaction(1)
                ->setIsTransactionClosed(0)
                ->registerAuthorizationNotification($amount, true);
            $this->_debug('Order: ' .  $orderCode .' AUTHORIZED');
        }
        else if ($status === 'SUCCESS') {
            if($order->canInvoice()) {
                $payment
                ->setTransactionId($orderCode)
                ->setShouldCloseParentTransaction(1)
                ->setIsTransactionClosed(0);

                $invoice = $order->prepareInvoice();
                $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE);
                $invoice->register();
                
                $transaction = $this->transactionFactory->create();
                
                $transaction->addObject($invoice)
                ->addObject($invoice->getOrder())
                ->save();
                
                $this->invoiceSender->send($invoice);
                $order->addStatusHistoryComment(
                    __('Notified customer about invoice #%1.', $invoice->getId())
                )
                ->setIsCustomerNotified(true);
            }
            $this->_debug('Order: ' .  $orderCode .' SUCCESS');
        }
        else {
            // Unknown status
            $order->addStatusHistoryComment('Unknown Worldpay Payment Status: ' . $status . ' for ' . $orderCode)
           ->setIsCustomerNotified(true);
        }
        $order->save();
    }

    private function getCheckoutMethod($quote)
    {
        if ($this->customerSession->isLoggedIn()) {
            return \Magento\Checkout\Model\Type\Onepage::METHOD_CUSTOMER;
        }
        if (!$quote->getCheckoutMethod()) {
            if ($this->checkoutData->isAllowedGuestCheckout($quote)) {
                $quote->setCheckoutMethod(\Magento\Checkout\Model\Type\Onepage::METHOD_GUEST);
            } else {
                $quote->setCheckoutMethod(\Magento\Checkout\Model\Type\Onepage::METHOD_REGISTER);
            }
        }
        return $quote->getCheckoutMethod();
    }
    
    public function readyMagentoQuote() {
        $quote = $this->checkoutSession->getQuote();

        $quote->reserveOrderId();
        $this->quoteRepository->save($quote);
        if ($this->getCheckoutMethod($quote) == \Magento\Checkout\Model\Type\Onepage::METHOD_GUEST) {
            $quote->setCustomerId(null)
            ->setCustomerEmail($quote->getBillingAddress()->getEmail())
            ->setCustomerIsGuest(true)
            ->setCustomerGroupId(\Magento\Customer\Model\Group::NOT_LOGGED_IN_ID);
        }

        $quote->getBillingAddress()->setShouldIgnoreValidation(true);
        if (!$quote->getIsVirtual()) {
            $quote->getShippingAddress()->setShouldIgnoreValidation(true);
            if (!$quote->getBillingAddress()->getEmail()
            ) {
                $quote->getBillingAddress()->setSameAsBilling(1);
            }
        }

        $quote->collectTotals();

        return $quote;
    }

    public function createMagentoOrder($quote) {
        try {
            $order = $this->quoteManagement->submit($quote);
            return $order;
        }
        catch (\Exception $e) {
            $orderId = $quote->getReservedOrderId();
            $payment = $quote->getPayment();
            $token = $payment->getAdditionalInformation('payment_token');
            $amount = $quote->getGrandTotal();
            $payment->setStatus(self::STATUS_ERROR);
            $payment->setAmount($amount);
            $payment->setLastTransId($orderId);
            $this->_debug($e->getMessage());

            \Magento\Checkout\Model\Session::restoreQuote();

            throw new \Exception($e->getMessage());
        }
    }

    public function sendMagentoOrder($order) {
        // $this->orderSender->send($order);
        $this->checkoutSession->start();

        $this->checkoutSession->clearHelperData();

        $this->checkoutSession->setLastOrderId($order->getId())
                ->setLastRealOrderId($order->getIncrementId())
                ->setLastOrderStatus($order->getStatus());
    }

    protected function _debug($debugData)
    {   
        if ($this->config->debugMode($this->_code)) {
            $this->logger->debug($debugData);
        }
    }

    protected function getSharedOrderDetails($quote, $currencyCode) {

        $billing = $quote->getBillingAddress();
        $shipping = $quote->getShippingAddress();

        $data = [];

        $data['orderDescription'] = $this->config->getPaymentDescription();

        if (!$data['orderDescription']) {
            $data['orderDescription'] = "Magento 2 Order";
        }

        $data['currencyCode'] = $currencyCode;
        $data['name'] = $billing->getName();

        $data['billingAddress'] = [
            "address1"=>$billing->getStreetLine(1),
            "address2"=>$billing->getStreetLine(2),
            "address3"=>$billing->getStreetLine(3),
            "postalCode"=>$billing->getPostcode(),
            "city"=>$billing->getCity(),
            "state"=>"",
            "countryCode"=>$billing->getCountryId(),
            "telephoneNumber"=>$billing->getTelephone()
        ];

        $data['deliveryAddress'] = [
            "firstName"=>$shipping->getFirstname(),
            "lastName"=>$shipping->getLastname(),
            "address1"=>$shipping->getStreetLine(1),
            "address2"=>$shipping->getStreetLine(2),
            "address3"=>$shipping->getStreetLine(3),
            "postalCode"=>$shipping->getPostcode(),
            "city"=>$shipping->getCity(),
            "state"=>"",
            "countryCode"=>$shipping->getCountryId(),
            "telephoneNumber"=>$shipping->getTelephone()
        ];

        $data['shopperIpAddress'] = $this->stripPortNumberFromIp($this->getClientIp());
        $data['shopperSessionId'] = $this->getSession();
        $data['shopperUserAgent'] = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
        $data['shopperAcceptHeader'] = '*/*';

        if ($this->backendAuthSession->isLoggedIn()) {
            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            $customer = $objectManager->create('Magento\Customer\Model\Customer')->load($this->sessionQuote->getCustomerId());
            $data['shopperEmailAddress'] = $customer->getEmail();
        } else {
            $data['shopperEmailAddress'] = $this->customerSession->getCustomer()->getEmail();
        }
        $data['siteCode'] = null;
        $siteCodes = $this->config->getSitecodes();
        if ($siteCodes) {
            foreach ($siteCodes as $siteCode) {
                // if ($siteCode['currency'] == $data['currencyCode']) {
                    $data['siteCode'] = $siteCode['site_code'];
                    $data['settlementCurrency'] = $siteCode['settlement_currency'];
                    break;
                // }
            }
        }
        if (!isset($data['settlementCurrency'])) {
            $data['settlementCurrency'] = $this->config->getSettlementCurrency();
        }
        return $data;
    }

    public function getClientIp()
    {
        return \Worldpay\Utils::getClientIp();
    }

    private function stripPortNumberFromIp($ipAddress)
    {
        return trim(explode(":", $ipAddress)[0]);
    }
}
