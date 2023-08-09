<?php

namespace Boodil\Payment\Controller\Payment;

use Boodil\Payment\Controller\BoodilpayAbstract;

class Index extends BoodilpayAbstract {

    /**
     * @return bool|void
     */
    public function execute() {
        if (!$this->getRequest()->getParam('uuid') || ($this->getRequest()->getParam('uuid') == $this->getCheckoutSession()->getOrderUuid())) {
            return;
        }
        if ((!$this->getRequest()->getParam('consent') && $this->getRequest()->getParam('error') == 'access_denied') || (!$this->getRequest()->getParam('consent') && !$this->getQuote()->getId())) {
            $this->messageManager->addErrorMessage(__("Payment cancelled"));
            $this->_redirect('checkout/cart');
            return;
        }
        try {
            $completeStatusCode = [
                'ACSC',
                'ACCC',
                'ACCP',
                'ACSP',
                'ACTC',
                'ACWC',
                'ACWP',
                'ACFC'
            ];
            $pendingStatusCode = [
                'PDNG',
                'RCVD',
                'PART',
                'PATC'
            ];
            if ($this->getRequest()->getParam('uuid') && $this->getRequest()->getParam('mobile') == true) {
                $results = $this->getTransactionApi($this->getRequest()->getParam('uuid'));
            } else {
                $results = $this->createPaymentsApi();
            }
            if (isset($results['result']['statusCode']) && in_array($results['result']['statusCode'], $completeStatusCode)) {
                if ($this->getQuote()->getId() == "") {
                    $this->_redirect('boodil/payment/success');
                    return;
                }
                try {
                    $this->_success($results);
                    return $this->_redirect($this->getSuccessUrl());
                } catch (\Exception $e) {
                    $this->messageManager->addErrorMessage(__("Unable to process order, please try again."));
                    $this->_redirect('checkout/cart');
                }
            } elseif (isset($results['result']['statusCode']) && in_array($results['result']['statusCode'], $pendingStatusCode)) {
                if ($this->getQuote()->getId() == "") {
                    $this->_redirect('boodil/payment/success');
                    return;
                }
                try {
                    $this->registry->register('status', 'PDNG');
                    $this->_success($results);
                    $this->_redirect($this->getSuccessUrl());
                } catch (\Exception $e) {
                    $this->messageManager->addErrorMessage(__("Unable to process order, please try again."));
                    $this->_redirect('checkout/cart');
                }
            } elseif (isset($results['result']['statusCode']) == 'RJCT') {
                $this->messageManager->addErrorMessage(__("Payment initiation or individual transaction included in the payment initiation has been rejected."));
                $this->_redirect('checkout/cart');
                return;
            } elseif (isset($results['result']['statusCode']) == 'CANC') {
                $this->messageManager->addErrorMessage(__("Payment initiation has been cancelled before execution"));
                $this->_redirect('checkout/cart');
                return;
            } elseif (isset($results['error']['message'])) {
                $errors = [
                    "message" => __($results['error']['message']),
                    "tracingId" => $results['error']['tracingId']
                ];
                $this->logger->debug($this->json->serialize($errors) . "\n");
                $this->messageManager->addErrorMessage(__($results['error']['message']));
                $this->_redirect('checkout/cart');
                return;
            } else {
                $this->messageManager->addErrorMessage(__("An error occurred on the server. Please try to place the order again."));
                $this->logger->debug("Server Error: " . $this->json->serialize($results));
                $this->_redirect('checkout/cart');
                return;
            }
        } catch (\Exception $e) {
            $this->logger->debug($e->getMessage());
            $this->messageManager->addErrorMessage(__($e->getMessage()));
            $this->_redirect('checkout/cart');
            return;
        }
    }

    /**
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    protected function _success($results) {
        try {
            $transaction = $this->_service->insertDataIntoTransactions($results);
        } catch (\Exception $e) {
            $this->logger->debug($e->getMessage());
            //more than likely duplicate request called by mobile / qr code.
            throw $e;
        }
        try {
            $this->_initService();
            $this->_service->placeOrder();
            $order = $this->_service->getOrder();
            if (!$order) {
                throw new \Exception('Unable to create order');
            }
            //add order ID in if attempt was successful
            $transaction->setOrderId($order->getId());
            $transaction->save();
            $quoteId = $this->getQuote()->getId();
            $this->getCheckoutSession()
                ->clearHelperData()
                ->setLastQuoteId($quoteId)
                ->setLastSuccessQuoteId($quoteId)
                ->setLastOrderId($order->getId())
                ->setLastRealOrderId($order->getIncrementId())
                ->setLastOrderStatus($order->getStatus())
                ->setOrderUuid($results['uuid'])
                ->unsQuoteId();
        } catch (\Exception $e) {
            $this->logger->debug($e->getMessage());
            throw $e;
        }
    }

}
