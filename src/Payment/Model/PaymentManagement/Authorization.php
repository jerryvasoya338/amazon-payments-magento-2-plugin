<?php
/**
 * Copyright 2016 Amazon.com, Inc. or its affiliates. All Rights Reserved.
 *
 * Licensed under the Apache License, Version 2.0 (the "License").
 * You may not use this file except in compliance with the License.
 * A copy of the License is located at
 *
 *  http://aws.amazon.com/apache2.0
 *
 * or in the "license" file accompanying this file. This file is distributed
 * on an "AS IS" BASIS, WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either
 * express or implied. See the License for the specific language governing
 * permissions and limitations under the License.
 */
namespace Amazon\Payment\Model\PaymentManagement;

use Amazon\Core\Client\ClientFactoryInterface;
use Amazon\Payment\Api\Data\PendingAuthorizationInterface;
use Amazon\Payment\Api\Data\PendingAuthorizationInterfaceFactory;
use Amazon\Payment\Api\PaymentManagement\AuthorizationInterface;
use Amazon\Payment\Api\PaymentManagementInterface;
use Amazon\Payment\Domain\AmazonAuthorizationDetailsResponseFactory;
use Amazon\Payment\Domain\AmazonGetOrderDetailsResponseFactory;
use Amazon\Payment\Domain\AmazonOrderStatus;
use Amazon\Payment\Domain\Details\AmazonAuthorizationDetails;
use Amazon\Payment\Domain\Details\AmazonOrderDetails;
use Amazon\Payment\Domain\Validator\AmazonAuthorization;
use Amazon\Payment\Exception\SoftDeclineException;
use Exception;
use Magento\Backend\Model\UrlInterface;
use Magento\Framework\Api\SearchCriteriaBuilderFactory;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Notification\NotifierInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use Magento\Sales\Api\Data\TransactionInterface;
use Magento\Sales\Api\InvoiceRepositoryInterface;
use Magento\Sales\Api\OrderPaymentRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order\Payment\Transaction;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class Authorization extends AbstractOperation implements AuthorizationInterface
{
    /**
     * @var PendingAuthorizationInterfaceFactory
     */
    protected $pendingAuthorizationFactory;

    /**
     * @var ClientFactoryInterface
     */
    protected $clientFactory;

    /**
     * @var AmazonAuthorizationDetailsResponseFactory
     */
    protected $amazonAuthorizationDetailsResponseFactory;

    /**
     * @var AmazonAuthorization
     */
    protected $amazonAuthorizationValidator;

    /**
     * @var OrderPaymentRepositoryInterface
     */
    protected $orderPaymentRepository;

    /**
     * @var OrderRepositoryInterface
     */
    protected $orderRepository;

    /**
     * @var ManagerInterface
     */
    protected $eventManager;

    /**
     * @var AmazonGetOrderDetailsResponseFactory
     */
    protected $amazonGetOrderDetailsResponseFactory;

    /**
     * @var StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @var PaymentManagementInterface
     */
    protected $paymentManagement;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var bool
     */
    protected $throwExceptions = false;

    /**
     * Authorization constructor.
     *
     * @param ClientFactoryInterface                    $clientFactory
     * @param PendingAuthorizationInterfaceFactory      $pendingAuthorizationFactory
     * @param AmazonAuthorizationDetailsResponseFactory $amazonAuthorizationDetailsResponseFactory
     * @param AmazonAuthorization                       $amazonAuthorizationValidator
     * @param NotifierInterface                         $notifier
     * @param UrlInterface                              $urlBuilder
     * @param SearchCriteriaBuilderFactory              $searchCriteriaBuilderFactory
     * @param OrderPaymentRepositoryInterface           $orderPaymentRepository
     * @param OrderRepositoryInterface                  $orderRepository
     * @param InvoiceRepositoryInterface                $invoiceRepository
     * @param ManagerInterface                          $eventManager
     * @param AmazonGetOrderDetailsResponseFactory      $amazonGetOrderDetailsResponseFactory
     * @param StoreManagerInterface                     $storeManager
     * @param PaymentManagementInterface                $paymentManagement
     * @param LoggerInterface                           $logger
     */
    public function __construct(
        ClientFactoryInterface $clientFactory,
        PendingAuthorizationInterfaceFactory $pendingAuthorizationFactory,
        AmazonAuthorizationDetailsResponseFactory $amazonAuthorizationDetailsResponseFactory,
        AmazonAuthorization $amazonAuthorizationValidator,
        NotifierInterface $notifier,
        UrlInterface $urlBuilder,
        SearchCriteriaBuilderFactory $searchCriteriaBuilderFactory,
        OrderPaymentRepositoryInterface $orderPaymentRepository,
        OrderRepositoryInterface $orderRepository,
        InvoiceRepositoryInterface $invoiceRepository,
        ManagerInterface $eventManager,
        AmazonGetOrderDetailsResponseFactory $amazonGetOrderDetailsResponseFactory,
        StoreManagerInterface $storeManager,
        PaymentManagementInterface $paymentManagement,
        LoggerInterface $logger
    ) {
        $this->clientFactory                             = $clientFactory;
        $this->pendingAuthorizationFactory               = $pendingAuthorizationFactory;
        $this->amazonAuthorizationDetailsResponseFactory = $amazonAuthorizationDetailsResponseFactory;
        $this->amazonAuthorizationValidator              = $amazonAuthorizationValidator;
        $this->orderPaymentRepository                    = $orderPaymentRepository;
        $this->orderRepository                           = $orderRepository;
        $this->eventManager                              = $eventManager;
        $this->amazonGetOrderDetailsResponseFactory      = $amazonGetOrderDetailsResponseFactory;
        $this->storeManager                              = $storeManager;
        $this->paymentManagement                         = $paymentManagement;
        $this->logger                                    = $logger;

        parent::__construct($notifier, $urlBuilder, $searchCriteriaBuilderFactory, $invoiceRepository);
    }

    /**
     * {@inheritdoc}
     */
    public function setThrowExceptions($throwExceptions)
    {
        $this->throwExceptions = $throwExceptions;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function updateAuthorization(
        $pendingAuthorizationId,
        AmazonAuthorizationDetails $authorizationDetails = null,
        AmazonOrderDetails $orderDetails = null
    ) {
        try {
            $pendingAuthorization = $this->pendingAuthorizationFactory->create();
            $pendingAuthorization->getResource()->beginTransaction();
            $pendingAuthorization->setLockOnLoad(true);
            $pendingAuthorization->load($pendingAuthorizationId);

            if ($pendingAuthorization->getOrderId()) {
                if ($pendingAuthorization->isProcessed()) {
                    $this->processNewAuthorization($pendingAuthorization, $orderDetails);
                } else {
                    $this->processUpdateAuthorization($pendingAuthorization, $authorizationDetails);
                }
            }

            $pendingAuthorization->getResource()->commit();
        } catch (Exception $e) {
            $this->logger->error($e);
            $pendingAuthorization->getResource()->rollBack();

            if ($this->throwExceptions) {
                throw $e;
            }
        }
    }

    protected function processUpdateAuthorization(
        PendingAuthorizationInterface $pendingAuthorization,
        AmazonAuthorizationDetails $authorizationDetails = null
    ) {
        $order   = $this->orderRepository->get($pendingAuthorization->getOrderId());
        $payment = $this->orderPaymentRepository->get($pendingAuthorization->getPaymentId());
        $order->setPayment($payment);
        $order->setData(OrderInterface::PAYMENT, $payment);

        $storeId = $order->getStoreId();
        $this->storeManager->setCurrentStore($storeId);

        $authorizationId = $pendingAuthorization->getAuthorizationId();

        if (null === $authorizationDetails) {
            $responseParser = $this->clientFactory->create($storeId)->getAuthorizationDetails([
                'amazon_authorization_id' => $authorizationId
            ]);

            $response             = $this->amazonAuthorizationDetailsResponseFactory->create(['response' => $responseParser]);
            $authorizationDetails = $response->getDetails();
        }

        $capture = $authorizationDetails->hasCapture();

        try {
            $this->amazonAuthorizationValidator->validate($authorizationDetails);

            if (! $authorizationDetails->isPending()) {
                $this->completePendingAuthorization($order, $payment, $pendingAuthorization, $capture);
            }
        } catch (SoftDeclineException $e) {
            $this->softDeclinePendingAuthorization($order, $payment, $pendingAuthorization, $capture);
        } catch (\Exception $e) {
            $this->hardDeclinePendingAuthorization($order, $payment, $pendingAuthorization, $capture);
        }
    }

    protected function completePendingAuthorization(
        OrderInterface $order,
        OrderPaymentInterface $payment,
        PendingAuthorizationInterface $pendingAuthorization,
        $capture,
        TransactionInterface $newTransaction = null
    ) {
        $transactionId = ($capture) ? $pendingAuthorization->getCaptureId()
            : $pendingAuthorization->getAuthorizationId();

        $this->setProcessing($order);

        if ($capture) {
            $invoice = $this->getInvoiceAndSetPaid($transactionId, $order);

            if (! $newTransaction) {
                $this->paymentManagement->closeTransaction($transactionId, $payment, $order);
            } else {
                $invoice->setTransactionId($newTransaction->getTxnId());
            }

            $formattedAmount = $order->getBaseCurrency()->formatTxt($invoice->getBaseGrandTotal());
            $message         = __('Captured amount of %1 online', $formattedAmount);
            $payment->setDataUsingMethod(
                'base_amount_paid_online',
                $payment->formatAmount($invoice->getBaseGrandTotal())
            );
        } else {
            $formattedAmount = $order->getBaseCurrency()->formatTxt($payment->getBaseAmountAuthorized());
            $message         = __('Authorized amount of %1 online', $formattedAmount);
        }

        $transaction = ($newTransaction) ?: $this->paymentManagement->getTransaction($transactionId, $payment, $order);
        $payment->addTransactionCommentsToOrder($transaction, $message);

        $pendingAuthorization->delete();
        $order->save();
    }

    protected function softDeclinePendingAuthorization(
        OrderInterface $order,
        OrderPaymentInterface $payment,
        PendingAuthorizationInterface $pendingAuthorization,
        $capture
    ) {
        $transactionId = ($capture) ? $pendingAuthorization->getCaptureId()
            : $pendingAuthorization->getAuthorizationId();

        if ($capture) {
            $invoice = $this->getInvoice($transactionId, $order);
            $this->setPaymentReview($order);
            $formattedAmount = $order->getBaseCurrency()->formatTxt($invoice->getBaseGrandTotal());
            $message         = __('Declined amount of %1 online', $formattedAmount);
        } else {
            $formattedAmount = $order->getBaseCurrency()->formatTxt($payment->getBaseAmountAuthorized());
            $message         = __('Declined amount of %1 online', $formattedAmount);
        }

        $transaction = $this->paymentManagement->getTransaction($transactionId, $payment, $order);
        $payment->addTransactionCommentsToOrder($transaction, $message);
        $this->paymentManagement->closeTransaction($transactionId, $payment, $order);

        $pendingAuthorization->setProcessed(true);
        $pendingAuthorization->save();
        $order->save();

        $this->eventManager->dispatch(
            'amazon_payment_pending_authorization_soft_decline_after',
            [
                'order'                => $order,
                'pendingAuthorization' => $pendingAuthorization,
            ]
        );
    }

    protected function hardDeclinePendingAuthorization(
        OrderInterface $order,
        OrderPaymentInterface $payment,
        PendingAuthorizationInterface $pendingAuthorization,
        $capture
    ) {
        $transactionId = ($capture) ? $pendingAuthorization->getCaptureId()
            : $pendingAuthorization->getAuthorizationId();

        if ($capture) {
            $invoice         = $this->getInvoiceAndSetCancelled($transactionId, $order);
            $formattedAmount = $order->getBaseCurrency()->formatTxt($invoice->getBaseGrandTotal());
            $message         = __('Declined amount of %1 online', $formattedAmount);
            $this->addCaptureDeclinedNotice($order);
        } else {
            $formattedAmount = $order->getBaseCurrency()->formatTxt($payment->getBaseAmountAuthorized());
            $message         = __('Declined amount of %1 online', $formattedAmount);
        }

        $this->setOnHold($order);

        $transaction = $this->paymentManagement->getTransaction($transactionId, $payment, $order);
        $payment->addTransactionCommentsToOrder($transaction, $message);
        $this->paymentManagement->closeTransaction($transactionId, $payment, $order);

        $pendingAuthorization->delete();
        $order->save();

        $this->eventManager->dispatch(
            'amazon_payment_pending_authorization_hard_decline_after',
            [
                'order'                => $order,
                'pendingAuthorization' => $pendingAuthorization,
            ]
        );
    }

    protected function processNewAuthorization(
        PendingAuthorizationInterface $pendingAuthorization,
        AmazonOrderDetails $orderDetails = null
    ) {
        $order   = $this->orderRepository->get($pendingAuthorization->getOrderId());
        $payment = $this->orderPaymentRepository->get($pendingAuthorization->getPaymentId());
        $order->setPayment($payment);
        $order->setData(OrderInterface::PAYMENT, $payment);

        $storeId = $order->getStoreId();
        $this->storeManager->setCurrentStore($storeId);

        if (null === $orderDetails) {
            $responseParser = $this->clientFactory->create($storeId)->getOrderReferenceDetails([
                'amazon_order_reference_id' => $order->getExtensionAttributes()->getAmazonOrderReferenceId()
            ]);

            $response     = $this->amazonGetOrderDetailsResponseFactory->create(['response' => $responseParser]);
            $orderDetails = $response->getDetails();
        }

        if (AmazonOrderStatus::STATE_OPEN == $orderDetails->getStatus()->getState()) {
            $capture = $pendingAuthorization->isCapture();

            if ($capture) {
                $this->requestNewAuthorizationAndCapture($order, $payment, $pendingAuthorization);
            } else {
                $this->requestNewAuthorization($order, $payment, $pendingAuthorization);
            }
        }
    }

    protected function requestNewAuthorization(
        OrderInterface $order,
        OrderPaymentInterface $payment,
        PendingAuthorizationInterface $pendingAuthorization
    ) {
        $capture = false;

        try {
            $baseAmount = $payment->formatAmount($payment->getBaseAmountAuthorized());

            $method = $payment->getMethodInstance();
            $method->setStore($order->getStoreId());
            $method->authorizeInCron($payment, $baseAmount, $capture);

            $transaction = $payment->addTransaction(Transaction::TYPE_AUTH);

            $this->completePendingAuthorization(
                $order,
                $payment,
                $pendingAuthorization,
                $capture,
                $transaction
            );
        } catch (SoftDeclineException $e) {
            $this->softDeclinePendingAuthorization($order, $payment, $pendingAuthorization, $capture);
        } catch (\Exception $e) {
            $this->hardDeclinePendingAuthorization($order, $payment, $pendingAuthorization, $capture);
        }
    }

    protected function requestNewAuthorizationAndCapture(
        OrderInterface $order,
        OrderPaymentInterface $payment,
        PendingAuthorizationInterface $pendingAuthorization
    ) {
        $capture = true;

        try {
            $invoice = $this->getInvoice($pendingAuthorization->getCaptureId(), $order);

            $baseAmount = $payment->formatAmount($invoice->getBaseGrandTotal());

            $method = $payment->getMethodInstance();
            $method->setStore($order->getStoreId());
            $method->authorizeInCron($payment, $baseAmount, $capture);

            $transaction = $payment->addTransaction(Transaction::TYPE_CAPTURE, $invoice, true);

            $this->completePendingAuthorization(
                $order,
                $payment,
                $pendingAuthorization,
                $capture,
                $transaction
            );
        } catch (SoftDeclineException $e) {
            $this->softDeclinePendingAuthorization($order, $payment, $pendingAuthorization, $capture);
        } catch (\Exception $e) {
            $this->hardDeclinePendingAuthorization($order, $payment, $pendingAuthorization, $capture);
        }
    }
}
