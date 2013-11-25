<?php

namespace Scastells\SafetypayBundle\Controller;

use Mmoreram\PaymentCoreBundle\Exception\PaymentOrderNotFoundException;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Mmoreram\PaymentCoreBundle\Services\PaymentEventDispatcher;
use Doctrine\ORM\EntityManager;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Scastells\SafetypayBundle\SafetypayMethod;
use Mmoreram\PaymentCoreBundle\Exception\PaymentException;

/**
 * Class SafetypayController
 */
class SafetypayController extends controller
{
    /**
     * Payment execution
     *
     * @param Request $request Request element
     *
     * @throws PaymentOrderNotFoundException
     * @return RedirectResponse
     *
     * @Method("POST")
     * @Template()
     */
    public function executeAction(Request $request)
    {
        $paymentMethod = new SafetypayMethod();
        $paymentBridge = $this->get('payment.bridge');
       // $safetyManager = $this->get('safetypay.manager');

        /**
         * New order from cart must be created right here
         */
        $this->get('payment.event.dispatcher')->notifyPaymentOrderLoad($paymentBridge, $paymentMethod);


        /**
         * Order Not found Exception must be thrown just here
         */
        if (!$paymentBridge->getOrder()) {

            throw new PaymentOrderNotFoundException;
        }


        /**
         * Loading success route for returning from safetypay
         */
        $redirectSuccessUrl = $this->container->getParameter('safetypay.success.route');
        $redirectSuccessAppend = $this->container->getParameter('safetypay.success.order.append');
        $redirectSuccessAppendField = $this->container->getParameter('safetypay.success.order.field');

        $redirectSuccessData    = $redirectSuccessAppend
            ? array(
                $redirectSuccessAppendField => $this->get('payment.bridge')->getOrderId(),
            )
            : array();

        $successRoute = $this->generateUrl($redirectSuccessUrl, $redirectSuccessData, true);
        $safetyPayTransaction = $paymentBridge->getOrderId() . '#' . date('Ymdhis');
        $paymentMethod->setReference($paymentBridge->getOrderId() . '#' . date('Ymdhis'));


        /*
         * url send confirmation
         */
        $redirectResponseUrl = $this->container->getParameter('safetypay.controller.route.success.name');

        $responseRoute = $this->generateUrl($redirectResponseUrl, array('order_id' => $this->get('payment.bridge')->getOrderId()), true);


        $redirectFailUrl = $this->container->getParameter('safetypay.controller.route.fail.name');

        $failRoute = $this->generateUrl($redirectFailUrl, array('order_id' => $this->get('payment.bridge')->getOrderId()), true);

       try {

           $formView = $this
               ->get('safetypay.form.type.wrapper')
               ->buildForm($responseRoute, $failRoute, $safetyPayTransaction);

       } catch (PaymentException $e) {

           return $this->redirect($this->generateUrl('cart_fail', array('order_id' => $this->get('payment.bridge')->getOrderId())));
       }
        /**
         * Build form
         */
        $formView = $formView
            ->getForm($successRoute)
            ->createView();

        $this->get('payment.event.dispatcher')->notifyPaymentOrderDone($paymentBridge, $paymentMethod);
        return array(

            'safetypay_form' => $formView,
        );
    }


    /**
     * Payment success
     *
     * @param Request $request Request element
     *
     * @return RedirectSuccess
     *
     */
    public function successAction(Request $request)
    {
        $paymentMethod = new SafetypayMethod();
        $paymentBridge = $this->get('payment.bridge');

        $orderId = $request->query->get('order_id');

        $infoLog = array(
            'order_id'  => $orderId,
            'action'    => 'SafetyPaySuccessAction'
        );

        $this->get('logger')->addInfo($paymentMethod->getPaymentName(),$infoLog);


        $trans = $this->getDoctrine()->getRepository('SafetypayBridgeBundle:SafetypayOrderTransaction')
            ->findOneBy(array('order_id' => $orderId));

        $order = $paymentBridge->findOrder($trans->getOrder()->getId());
        $paymentBridge->setOrder($order);
        $paymentMethod->setReference($orderId);

        if ($orderId == $trans->getSafetyPayTransactionId()) {

            $this->get('payment.event.dispatcher')->notifyPaymentOrderSuccess($paymentBridge, $paymentMethod);

            $redirectUrl = $this->container->getParameter('safetypay.success.route');
            $redirectSuccessAppend = $this->container->getParameter('safetypay.success.order.append');
            $redirectSuccessAppendField = $this->container->getParameter('safetypay.success.order.field');

            $redirectData    = $redirectSuccessAppend
                ? array(
                    $redirectSuccessAppendField => $trans->getOrder()->getId(),
                )
                : array();
        }

        return $this->redirect($this->generateUrl($redirectUrl, $redirectData));
    }


    /**
     * Payment fail
     *
     * @param Request $request Request element
     *
     * @return RedirectFail
     *
     */
    public function failAction(Request $request)
    {
        $paymentMethod = new SafetypayMethod();
        $paymentBridge = $this->get('payment.bridge');

        $orderId = $request->query->get('order_id');
        $infoLog = array(
            'order_id'  => $orderId,
            'action'    => 'SafetyPayFailAction'
        );

        $this->get('logger')->addInfo($paymentMethod->getPaymentName(),$infoLog);


        $trans = $this->getDoctrine()->getRepository('SafetypayBridgeBundle:SafetypayOrderTransaction')
            ->findOneBy(array('order' => $orderId));

        $order = $paymentBridge->findOrder($trans->getOrder()->getId());
        $paymentBridge->setOrder($order);
        $paymentMethod->setReference($orderId);
        $orderTrans = explode('#', $trans->getSafetyPayTransactionId());

        if ($orderId == $orderTrans[0]) {

            $this->get('payment.event.dispatcher')->notifyPaymentOrderFail($paymentBridge, $paymentMethod);

            $redirectUrl = $this->container->getParameter('safetypay.fail.route');
            $redirectSuccessAppend = $this->container->getParameter('safetypay.fail.order.append');
            $redirectSuccessAppendField = $this->container->getParameter('safetypay.fail.order.field');

            $redirectData    = $redirectSuccessAppend
                ? array(
                    $redirectSuccessAppendField => $trans->getOrder()->getId(),
                )
                : array();

        }
        return $this->redirect($this->generateUrl($redirectUrl, $redirectData));
    }
}