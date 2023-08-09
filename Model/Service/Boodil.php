<?php

namespace Boodil\Payment\Model\Service;

use Boodil\Payment\Model\TransactionsFactory;
use Exception;
use Magento\Checkout\Helper\Data;
use Magento\Checkout\Model\Type\Onepage;
use Magento\Customer\Model\Group;
use Magento\Customer\Model\Session;
use Magento\Framework\App\RequestInterface;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Model\Quote;

class Boodil
{
    /**
     * @var Quote
     */
    private $_quote;

    /**
     * @var CartManagementInterface
     */
    private $_quoteManagement;

    /**
     * @var Session
     */
    private $_customerSession;

    /**
     * @var \Magento\Checkout\Helper\Data
     */
    private $_checkoutData;

    /**
     * @var TransactionsFactory
     */
    private $transactionsFactory;

    /**
     * @var RequestInterface
     */
    private $request;

    private $_order;

    /**
     * Boodil constructor.
     * @param Session $customerSession
     * @param Data $checkoutData
     * @param CartManagementInterface $quoteManagement
     * @param TransactionsFactory $transactionsFactory
     * @param RequestInterface $request
     * @param array $params
     * @throws Exception
     */
    public function __construct(
        Session $customerSession,
        Data $checkoutData,
        CartManagementInterface $quoteManagement,
        TransactionsFactory $transactionsFactory,
        RequestInterface $request,
        $params = []
    ) {
        if (isset($params['quote']) && $params['quote'] instanceof Quote) {
            $this->_quote = $params['quote'];
        } else {
            // phpcs:ignore Magento2.Exceptions.DirectThrow
            throw new Exception('Quote instance is required.');
        }
        $this->_customerSession = $customerSession;
        $this->_checkoutData = $checkoutData;
        $this->_quoteManagement = $quoteManagement;
        $this->request = $request;
        $this->transactionsFactory = $transactionsFactory;
    }

    /**
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function placeOrder()
    {
        $transaction = $this->transactionsFactory->create();
        $collection = $transaction->getCollection()
            ->addFieldToFilter('uuid', $this->request->getParam('uuid'))
            ->getFirstItem();

        if ($collection->getUuid() === $this->request->getParam('uuid')) {
            return;
        }

        if ($this->getCheckoutMethod() == Onepage::METHOD_GUEST) {
            $this->prepareGuestQuote();
        }

        $this->_quote->getPayment()->importData(['method' => 'boodil']);
        $this->_quote->collectTotals();
        $order = $this->_quoteManagement->submit($this->_quote);

        if (!$order) {
            return;
        }

        $this->_order = $order;
    }

    /**
     * @param $results
     * @throws Exception
     */
    public function insertDataIntoTransactions($results)
    {
        $transaction = $this->transactionsFactory->create();
        $transaction->setOrderId(null);
        $transaction->setUuid($results['uuid'] ?? '');
        $transaction->setDescription($results['result']['description'] ?? '');
        $transaction->setStatus($results['result']['status'] ?? '');
        $transaction->setStatusCode($results['result']['statusCode'] ?? '');
        $transaction->setPoints($results['result']['points'] ?? '');
        $transaction->save();
        return $transaction;        
    }

    /**
     * @return string
     */
    protected function getCheckoutMethod()
    {
        if ($this->getCustomerSession()->isLoggedIn()) {
            return Onepage::METHOD_CUSTOMER;
        }
        if (!$this->_quote->getCheckoutMethod()) {
            if ($this->_checkoutData->isAllowedGuestCheckout($this->_quote)) {
                $this->_quote->setCheckoutMethod(Onepage::METHOD_GUEST);
            } else {
                $this->_quote->setCheckoutMethod(Onepage::METHOD_REGISTER);
            }
        }
        return $this->_quote->getCheckoutMethod();
    }

    /**
     * Prepare quote for guest checkout order submit
     *
     * @return $this
     */
    protected function prepareGuestQuote()
    {
        $quote = $this->_quote;
        $quote->setCustomerId(null)
            ->setCustomerEmail($quote->getBillingAddress()->getEmail())
            ->setCustomerIsGuest(true)
            ->setCustomerGroupId(Group::NOT_LOGGED_IN_ID);
        return $this;
    }

    /**
     * @return Session
     */
    public function getCustomerSession()
    {
        return $this->_customerSession;
    }

    /**
     * @return mixed
     */
    public function getOrder()
    {
        return $this->_order;
    }
}
