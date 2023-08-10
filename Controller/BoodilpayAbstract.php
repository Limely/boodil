<?php
namespace Boodil\Payment\Controller;

use Boodil\Payment\Api\BoodilApiInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Registry;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Quote\Model\QuoteFactory;
use Magento\Framework\UrlInterface;
use Boodil\Payment\Model\Service\BoodilFactory;
use Boodil\Payment\Logger\Logger;
use Boodil\Payment\Model\TransactionsFactory;

abstract class BoodilpayAbstract extends Action
{
    /**
     * @var CheckoutSession
     */
    protected $checkoutSession;

    /**
     * @var QuoteFactory
     */
    private $quoteFactory;

    /**
     * @var UrlInterface
     */
    private $_urlInterface;

    /**
     * @var BoodilApiInterface
     */
    private $boodileApiInterface;

    /**
     * @var JsonFactory
     */
    protected $resultJsonFactory;

    /**
     * @var BoodilFactory
     */
    protected $boodilService;

    /**
     * @var Logger
     */
    protected $logger;

    /**
     * @var \Magento\Quote\Model\Quote
     */
    protected $quote;

    /**
     * @var \Boodil\Payment\Model\Service\Boodil
     */
    protected $_service;

    /**
     * @var Registry
     */
    protected $registry;

    /**
     * @var Json
     */
    protected $json;
    
    /**     
     * @var TransactionsFactory
     */
    protected $transactionsFactory;

    /**
     * BoodilpayAbstract constructor.
     * @param Context $context
     * @param QuoteFactory $quoteFactory
     * @param UrlInterface $urlInterface
     * @param BoodilApiInterface $boodileApiInterface
     * @param JsonFactory $resultJsonFactory
     * @param BoodilFactory $boodilService
     * @param Registry $registry
     * @param Json $json
     * @param Logger $logger
     * @param CheckoutSession $checkoutSession
     * @param TransactionsFactory $transactionsFactory
     */
    public function __construct(
        Context $context,
        QuoteFactory $quoteFactory,
        UrlInterface $urlInterface,
        BoodilApiInterface $boodileApiInterface,
        JsonFactory $resultJsonFactory,
        BoodilFactory $boodilService,
        Registry $registry,
        Json $json,
        Logger $logger,
        CheckoutSession $checkoutSession,
        TransactionsFactory $transactionsFactory
    ) {
        $this->checkoutSession = $checkoutSession;
        $this->quoteFactory = $quoteFactory;
        $this->_urlInterface = $urlInterface;
        $this->logger = $logger;
        $this->boodileApiInterface = $boodileApiInterface;
        $this->resultJsonFactory = $resultJsonFactory;
        $this->boodilService = $boodilService;
        $this->registry = $registry;
        $this->json = $json;
        $this->transactionsFactory = $transactionsFactory;
        parent::__construct($context);
    }

    /**
     * @return mixed|string
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function createTransactionsApi()
    {
        $quoteId = $this->getQuoteId();
        $order = $this->quoteFactory->create()->load($quoteId);
        $items = $order->getAllVisibleItems();
        $itemData = [];
        foreach ($items as $item) {
            $itemData[] = [
                "sku" => $item->getSku(),
                "name" => $item->getName(),
                "qty" => $item->getQty(),
                "price" => $item->getPriceInclTax()
            ];
        }
        $params = [
            "merchantUuid" => $this->boodileApiInterface->getMerchantUuid(),
            /*"reference" => $order->getReservedOrderId(),*/
            "email" => $order->getCustomerEmail() ?? $order->getBillingAddress()->getEmail(),
            "amount" => $order->getGrandTotal(),
            "currency" => $order->getQuoteCurrencyCode(),
            "redirectUrl" => $this->_urlInterface->getUrl('boodil/payment/index'),
            "customerId" => $order->getCustomerId(),
            "firstName" => $order->getCustomerFirstname() ?? $order->getBillingAddress()->getName(),
            "middleName" => $order->getCustomerMiddlename() ?? $order->getBillingAddress()->getMiddlename(),
            "surname" => $order->getCustomerLastname() ?? $order->getBillingAddress()->getLastname(),
            "address1" => $order->getBillingAddress()->getStreetLine(1),
            "address2" => $order->getBillingAddress()->getStreetLine(2),
            "phone" => $order->getBillingAddress()->getTelephone(),
            "postcode" => $order->getBillingAddress()->getPostcode(),
            "city" => $order->getBillingAddress()->getCity(),
            "country" => $order->getBillingAddress()->getCountry(),
            "c1" => $quoteId,
            "cart" => $itemData
        ];

        $headers = $this->boodileApiInterface->getAuthHeaders();
        $boodilApiUrl = $this->boodileApiInterface->getApiUrl("transactions");

        try {
            $merchantUuid = $this->boodileApiInterface->callCurl(
                $boodilApiUrl,
                $params,
                'POST',
                $headers,
                true
            );
        } catch (\Exception $e) {
            $this->logger->debug('Boodil\\Payment\\Controller\\BoodilpayAbstract\\createTransactionsApi: '. $e->getMessage());
            return $e->getMessage();
        }

        return $merchantUuid;
    }

    /**
     * @return mixed|string
     */
    public function createPaymentsApi()
    {
        $params = [
            "merchantUuid" => $this->boodileApiInterface->getMerchantUuid(),
            "uuid" => $this->getRequest()->getParam('uuid'),
            "consentToken" => $this->getRequest()->getParam('consent')
        ];
        $headers = $this->boodileApiInterface->getAuthHeaders();
        $boodilApiUrl = $this->boodileApiInterface->getApiUrl("payments");

        try {
            $results = $this->boodileApiInterface->callCurl(
                $boodilApiUrl,
                $params,
                'POST',
                $headers,
                true
            );
            $this->logger->debug('Boodil\\Payment\\Controller\\BoodilpayAbstract\\Request: '. $this->json->serialize($params). "\n");
            $this->logger->debug('Boodil\\Payment\\Controller\\BoodilpayAbstract\\createPaymentsApi: '. $this->json->serialize($results). "\n");
        } catch (\Exception $e) {
            $this->logger->debug('Boodil\\Payment\\Controller\\BoodilpayAbstract\\createPaymentsApi: '. $e->getMessage());
            return $e->getMessage();
        }

        return $results;
    }

    /**
     * @return mixed|string
     */
    public function getTransactionApi($uuid)
    {
        $params = [
            "uuid" => $uuid,
        ];
        $headers = $this->boodileApiInterface->getAuthHeaders();
        $boodilApiUrl = $this->boodileApiInterface->getApiUrl("transactions/status");
        try {
            $results = $this->boodileApiInterface->callCurl(
                $boodilApiUrl,
                $params,
                'GET',
                $headers,
                true
            );
            $this->logger->debug('Boodil\\Payment\\Controller\\BoodilpayAbstract\\getTransactionApi: '. $this->json->serialize($results). "\n");
        } catch (\Exception $e) {
            $this->logger->debug('Boodil\\Payment\\Controller\\BoodilpayAbstract\\getTransactionApi: '. $e->getMessage());
            return $e->getMessage();
        }

        return $results;
    }

    /**
     * @return int
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getQuoteId()
    {
        return (int)$this->getQuote()->getId();
    }

    /**
     * @return \Magento\Quote\Api\Data\CartInterface|\Magento\Quote\Model\Quote
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    protected function getQuote()
    {
        if (!$this->quote) {
            $this->quote = $this->getCheckoutSession()->getQuote();
        }
        return $this->quote;
    }

    /**
     * @return CheckoutSession
     */
    protected function getCheckoutSession()
    {
        return $this->checkoutSession;
    }

    /**
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    protected function _initService()
    {
        $quote = $this->getQuote();

        if (!$this->_service) {
            $parameters = [
                'params' => [
                    'quote' => $quote,
                ],
            ];
            $this->_service = $this->boodilService->create($parameters);
        }
    }

    /**
     * @return string
     */
    public function getSuccessUrl()
    {
        return $this->boodileApiInterface->getSuccessUrl();
    }
}
