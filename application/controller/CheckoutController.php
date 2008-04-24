<?php

ClassLoader::import('application.model.Currency');
ClassLoader::import('application.model.order.CustomerOrder');
ClassLoader::import('application.model.order.ExpressCheckout');
ClassLoader::import('application.model.order.Transaction');
ClassLoader::import('application.model.order.LiveCartTransaction');
ClassLoader::import('application.model.order.SessionOrder');

/**
 *  Handles order checkout process
 *
 *  The order checkout consists of the following steps:
 *
 *  1. Determine user status
 *
 *	  If the user is logged in, this step is skipped
 *	  If the user is not logged in there are 2 or 3 choices depending on configuration:
 *		  a) log in
 *		  b) create a new user account
 *		  c) continue checkout without registration (anonymous checkout).
 *			 In this case the user account will be created automatically
 *
 *  2. Process login
 *
 *	  If the user is already logged in or is checking out anonymously this step is skipped.
 *
 *  3. Select or enter billing and shipping addresses
 *
 *	  If the user has just been registered, this step is skipped, as these addresses have already been provided
 *	  If the user was logged in, the billing and shipping addresses have to be selected (or new addresses entered/edited)
 *
 *  4. Select shipping method and calculate tax
 *
 *	  Based on the shipping addresses, determine the available shipping methods and costs.
 *	  Based on the shipping or billing address (depending on config), calculate taxes if any.
 *
 *  5. Confirm order totals and select payment method
 *
 *  6. Enter payment details
 *
 *	  Redirected to external site if it's a 3rd party payment processor (like Paypal)
 *	  This step is skipped if a non-online payment method is selected (check, wire transfer, phone, etc.)
 *
 *  7. Process payment and reserve products
 *
 *	  This step is skipped also if the payment wasn't made
 *	  If the payment was attempted, but unsuccessful, return to payment form (6)
 *
 *  8. Process order and send invoice (optional)
 *
 *	  Whether the order is processed, depends on the configuration (auto vs manual processing)
 *
 *  9. Show the order confirmation page
 *
 * @author Integry Systems
 * @package application.controller
 */
class CheckoutController extends FrontendController
{
	const STEP_ADDRESS = 3;
	const STEP_SHIPPING = 4;
	const STEP_PAYMENT = 5;

	public function init()
	{
		parent::init();
		$this->addBreadCrumb($this->translate('_checkout'), $this->router->createUrl(array('controller' => 'order', 'action' => 'index'), true));

		$action = $this->request->getActionName();

		if ('index' == $action)
		{
			return false;
		}

		$this->addBreadCrumb($this->translate('_select_addresses'), $this->router->createUrl(array('controller' => 'checkout', 'action' => 'selectAddress'), true));

		if ('selectAddress' == $action)
		{
			return false;
		}

		$this->addBreadCrumb($this->translate('_shipping'), $this->router->createUrl(array('controller' => 'checkout', 'action' => 'shipping'), true));

		if ('shipping' == $action)
		{
			return false;
		}

		$this->addBreadCrumb($this->translate('_pay'), $this->router->createUrl(array('controller' => 'checkout', 'action' => 'pay'), true));
	}

	/**
	 *  1. Determine user status
	 */
	public function index()
	{
		if ($this->user->isLoggedIn())
		{
			// try to go to payment page
			return new ActionRedirectResponse('checkout', 'pay');
		}
		else
		{
			return new ActionRedirectResponse('user', 'checkout');
		}
	}

	/**
	 *  Redirect to an external site to acquire customer information and initiate payment (express checkout)
	 */
	public function express()
	{
		// redirect to external site
		$class = $this->request->get('id');

		$handler = $this->application->getExpressPaymentHandler($class, $this->getTransaction());

		$returnUrl = $this->router->createFullUrl($this->router->createUrl(array('controller' => 'checkout', 'action' => 'expressReturn', 'id' => $class), true));
		$cancelUrl = $this->router->createFullUrl($this->router->createUrl(array('controller' => 'order'), true));

		$url = $handler->getInitUrl($returnUrl, $cancelUrl, !$handler->getConfigValue('AUTHONLY'));

		return new RedirectResponse($url);
	}

	public function expressReturn()
	{
		$class = $this->request->get('id');

		$handler = $this->application->getExpressPaymentHandler($class, $this->getTransaction());
		$details = $handler->getTransactionDetails($this->request->toArray());

		$address = UserAddress::getNewInstanceByTransaction($details);
		$address->save();

		$paymentData = array_diff_key($this->request->toArray(), array_flip(array('controller', 'action', 'id', 'route', 'PHPSESSID')));

		$express = ExpressCheckout::getNewInstance($this->order, $handler);
		$express->address->set($address);
		$express->paymentData->set(serialize($paymentData));
		$express->save();

		// auto-login user if anonymous
		if ($this->user->isAnonymous())
		{
			// create new user account if it doesn't exist
			if (!($user = User::getInstanceByEmail($details->email->get())))
			{
				$user = User::getNewInstance($details->email->get());
				$user->firstName->set($details->firstName->get());
				$user->lastName->set($details->lastName->get());
				$user->companyName->set($details->companyName->get());
				$user->isEnabled->set(true);
				$user->save();
			}

			SessionUser::setUser($user);
			$this->order->user->set($user);
		}

		$this->order->billingAddress->set($address);
		$this->order->shippingAddress->set($address);
		$this->order->save();

		return new ActionRedirectResponse('checkout', 'shipping');
	}

	/**
	 *  3. Select or enter billing and shipping addresses
	 *	@role login
	 */
	public function selectAddress()
	{
		$this->user->loadAddresses();

		// check if the user has created a billing address
		if (!$this->user->defaultBillingAddress->get())
		{
			return new ActionRedirectResponse('user', 'addBillingAddress', array('returnPath' => true));
		}

		if ($redirect = $this->validateOrder($this->order))
		{
			return $redirect;
		}

		$form = $this->buildAddressSelectorForm($this->order);

		if ($this->order->billingAddress->get())
		{
			$form->set('billingAddress', $this->order->billingAddress->get()->getID());
		}
		else
		{
			if ($this->user->defaultBillingAddress->get())
			{
				$form->set('billingAddress', $this->user->defaultBillingAddress->get()->userAddress->get()->getID());
			}
		}

		if ($this->order->shippingAddress->get())
		{
			$form->set('shippingAddress', $this->order->shippingAddress->get()->getID());
		}
		else
		{
			if ($this->user->defaultShippingAddress->get())
			{
				$form->set('shippingAddress', $this->user->defaultShippingAddress->get()->userAddress->get()->getID());
			}
		}

		$form->set('sameAsBilling', (int)($form->get('billingAddress') == $form->get('shippingAddress') || !$this->user->defaultShippingAddress->get()));

		$response = new ActionResponse();
		$response->set('billingAddresses', $this->user->getBillingAddressArray());
		$response->set('shippingAddresses', $this->user->getShippingAddressArray());
		$response->set('form', $form);
		$response->set('order', $this->order->toArray());
		return $response;
	}

	/**
	 *	@role login
	 */
	public function doSelectAddress()
	{
		$this->user->loadAddresses();

		if (!$this->buildAddressSelectorValidator($this->order)->isValid())
		{
			return new ActionRedirectResponse('checkout', 'selectAddress');
		}

		try
		{
			$f = new ARSelectFilter();
			$f->setCondition(new EqualsCond(new ARFieldHandle('BillingAddress', 'userID'), $this->user->getID()));
			$f->mergeCondition(new EqualsCond(new ARFieldHandle('BillingAddress', 'userAddressID'), $this->request->get('billingAddress')));
			$r = ActiveRecordModel::getRecordSet('BillingAddress', $f, array('UserAddress'));

			if (!$r->size())
			{
				throw new ApplicationException('Invalid billing address');
			}

			$billing = $r->get(0);
			$this->order->billingAddress->set($billing->userAddress->get());

			// shipping address
			if ($this->order->isShippingRequired())
			{

				if ($this->request->get('sameAsBilling'))
				{
					$shipping = $billing;
				}
				else
				{

					$f = new ARSelectFilter();
					$f->setCondition(new EqualsCond(new ARFieldHandle('ShippingAddress', 'userID'), $this->user->getID()));
					$f->mergeCondition(new EqualsCond(new ARFieldHandle('ShippingAddress', 'userAddressID'), $this->request->get('shippingAddress')));
					$r = ActiveRecordModel::getRecordSet('ShippingAddress', $f, array('UserAddress'));

					if (!$r->size())
					{
						throw new ApplicationException('Invalid shipping address');
					}

					$shipping = $r->get(0);
				}

				$this->order->shippingAddress->set($shipping->userAddress->get());
			}

			$this->order->resetShipments();
		}
		catch (Exception $e)
		{
			throw $e;
			return new ActionRedirectResponse('checkout', 'selectAddress');
		}

		SessionOrder::save($this->order);

		return new ActionRedirectResponse('checkout', 'shipping');
	}

	/**
	 *  4. Select shipping methods
	 *	@role login
	 */
	public function shipping()
	{
		if ($redirect = $this->validateOrder($this->order, self::STEP_SHIPPING))
		{
			return $redirect;
		}

		if (!$this->order->isShippingRequired())
		{
			return new ActionRedirectResponse('checkout', 'pay');
		}

		$shipments = $this->order->getShipments();

		$form = $this->buildShippingForm($shipments);
		$zone = $this->order->getDeliveryZone();

		$needSelecting = false;

		foreach ($shipments as $key => $shipment)
		{
			$shipmentRates = $zone->getShippingRates($shipment);
			$shipment->setAvailableRates($shipmentRates);

			if ($shipmentRates->size() > 1)
			{
				$needSelecting = true;
			}
			else if (!$shipmentRates->size())
			{
				$validator = $this->buildAddressSelectorValidator($this->order);
				$validator->triggerError('selectedAddress', $this->translate('_err_no_rates_for_address'));
				$validator->saveState();

			 	return new ActionRedirectResponse('checkout', 'selectAddress');
			}
			else
			{
				$shipment->setRateId($shipmentRates->get(0)->getServiceId());
			}

			$rates[$key] = $shipmentRates;
			if ($shipment->getSelectedRate())
			{
				$form->set('shipping_' . $key, $shipment->getSelectedRate()->getServiceID());
			}

			if (!$shipment->isShippable())
			{
				$download = $shipment;
				$downloadIndex = $key;
			}
		}

		SessionOrder::save($this->order);

		// only one shipping method for each shipment, so we pre-select it automatically
		if (!$needSelecting)
		{
			return new ActionRedirectResponse('checkout', 'pay');
		}

		$rateArray = array();
		foreach ($rates as $key => $rate)
		{
			$rateArray[$key] = $rate->toArray();
		}

		$response = new ActionResponse();

		$shipmentArray = $shipments->toArray();

		if (isset($download))
		{
			$response->set('download', $download->toArray());
			unset($shipmentArray[$downloadIndex]);
		}

		$response->set('shipments', $shipmentArray);
		$response->set('rates', $rateArray);
		$response->set('currency', $this->getRequestCurrency());
		$response->set('form', $form);
		$response->set('order', $this->order->toArray());

		return $response;
	}

	/**
	 *	@role login
	 */
	public function doSelectShippingMethod()
	{
		$shipments = $this->order->getShipments();

		if (!$this->buildShippingValidator($shipments)->isValid())
		{
			return new ActionRedirectResponse('checkout', 'shipping');
		}

		foreach ($shipments as $key => $shipment)
		{
			if ($shipment->isShippable())
			{
				$rates = $shipment->getAvailableRates();

				$selectedRateId = $this->request->get('shipping_' . $key);

				if (!$rates->getByServiceId($selectedRateId))
				{
					throw new ApplicationException('No rate found: ' . $key .' (' . $selectedRateId . ')');
					return new ActionRedirectResponse('checkout', 'shipping');
				}

				$shipment->setRateId($selectedRateId);
			}
		}

		SessionOrder::save($this->order);

		return new ActionRedirectResponse('checkout', 'pay');
	}

	/**
	 *  5. Make payment
	 *	@role login
	 */
	public function pay()
	{
		$this->order->loadAll();

		if ($redirect = $this->validateOrder($this->order, self::STEP_PAYMENT))
		{
			return $redirect;
		}

		// check for express checkout data for this order
		if (ExpressCheckout::getInstanceByOrder($this->order))
		{
			return new ActionRedirectResponse('checkout', 'payExpress');
		}

		$currency = $this->request->get('currency', $this->application->getDefaultCurrencyCode());

		$response = new ActionResponse();
		$response->set('order', $this->order->toArray());
		$response->set('currency', $this->getRequestCurrency());

		$ccHandler = $this->application->getCreditCardHandler();
		$ccForm = $this->buildCreditCardForm();
		$response->set('ccForm', $ccForm);
		if ($ccHandler)
		{
			$response->set('ccHandler', $ccHandler->toArray());

			$months = range(1, 12);
			$months = array_combine($months, $months);

			$years = range(date('Y'), date('Y') + 20);
			$years = array_combine($years, $years);

			$response->set('months', $months);
			$response->set('years', $years);
			$response->set('ccTypes', $this->application->getCardTypes($ccHandler));
		}

		// other payment methods
		$external = $this->application->getPaymentHandlerList(true);
		$response->set('otherMethods', $external);

		// auto redirect to external payment page if only one handler is enabled
		if (1 == count($external) && !$this->config->get('CC_ENABLE') && !$ccForm->getValidator()->getErrorList())
		{
			$this->request->set('id', $external[0]);
			return $this->redirect();
		}

		return $response;
	}

	/**
	 *	@role login
	 */
	public function payCreditCard()
	{
		if ($redirect = $this->validateOrder($this->order, self::STEP_PAYMENT))
		{
			return $redirect;
		}

		if (!$this->buildCreditCardValidator()->isValid())
		{
			return new ActionRedirectResponse('checkout', 'pay');
		}

		// already paid?
		if ($this->order->isPaid->get())
		{
			return new ActionRedirectResponse('checkout', 'completed');
		}

		ActiveRecordModel::beginTransaction();

		// process payment
		$handler = $this->application->getCreditCardHandler($this->getTransaction());
		if ($this->request->isValueSet('ccType'))
		{
			$handler->setCardType($this->request->get('ccType'));
		}

		$handler->setCardData($this->request->get('ccNum'), $this->request->get('ccExpiryMonth'), $this->request->get('ccExpiryYear'), $this->request->get('ccCVV'));

		if ($this->config->get('CC_AUTHONLY'))
		{
			$result = $handler->authorize();
		}
		else
		{
			$result = $handler->authorizeAndCapture();
		}

		if ($result instanceof TransactionResult)
		{
			$response = $this->registerPayment($result, $handler);
		}
		elseif ($result instanceof TransactionError)
		{
			// set error message for credit card form
			$validator = $this->buildCreditCardValidator();
			$validator->triggerError('creditCardError', $this->translate('_err_processing_cc'));
			$validator->saveState();

			$response = new ActionRedirectResponse('checkout', 'pay');
		}
		else
		{
			throw new Exception('Unknown transaction result type: ' . get_class($result));
		}

		ActiveRecordModel::commit();

		return $response;
	}

	/**
	 *	@role login
	 */
	public function payOffline()
	{
		if (!$this->config->get('OFFLINE_PAYMENT'))
		{
			return new ActionRedirectResponse('checkout', 'pay');
		}

		return $this->finalizeOrder();
	}

	/**
	 *	@role login
	 */
	public function payExpress()
	{
		$res = $this->validateExpressCheckout();
		if ($res instanceof Response)
		{
			return $res;
		}

		$response = new ActionResponse;
		$response->set('order', $this->order->toArray());
		$response->set('currency', $this->getRequestCurrency());
		$response->set('method', $res->toArray());
		return $response;
	}

	/**
	 *	@role login
	 */
	public function payExpressComplete()
	{
		$res = $this->validateExpressCheckout();
		if ($res instanceof Response)
		{
			return $res;
		}

		$handler = $res->getHandler($this->getTransaction());
		if ($handler->getConfigValue('AUTHONLY'))
		{
			$result = $handler->authorize();
		}
		else
		{
			$result = $handler->authorizeAndCapture();
		}

		if ($result instanceof TransactionResult)
		{
			return $this->registerPayment($result, $handler);
		}
		elseif ($result instanceof TransactionError)
		{
			ExpressCheckout::deleteInstancesByOrder($this->order);

			// set error message for credit card form
			$validator = $this->buildCreditCardValidator();
			$validator->triggerError('creditCardError', $result->getMessage());
			$validator->saveState();

			return new ActionRedirectResponse('checkout', 'pay');
		}
		else
		{
			throw new Exception('Unknown transaction result type: ' . get_class($result));
		}
	}

	/**
	 *  Redirect to a 3rd party payment processor website to complete the payment
	 *  (Paypal IPN, 2Checkout, Moneybookers, etc)
	 *
	 *	@role login
	 */
	public function redirect()
	{
		if ($redirect = $this->validateOrder($this->order, self::STEP_PAYMENT))
		{
			return $redirect;
		}

		$class = $this->request->get('id');
		$handler = $this->application->getPaymentHandler($class, $this->getTransaction());
		$handler->setNotifyUrl($this->router->createFullUrl($this->router->createUrl(array('controller' => 'checkout', 'action' => 'notify', 'id' => $class))));
		$handler->setReturnUrl($this->router->createFullUrl($this->router->createUrl(array('controller' => 'checkout', 'action' => 'completeExternal', 'id' => $this->order->getID()))));
		$handler->setSiteUrl($this->router->createFullUrl($this->router->createUrl(array('controller' => 'index', 'action' => 'index'))));
		return new RedirectResponse($handler->getUrl());
	}

	/**
	 *  Payment confirmation post-back URL for 3rd party payment processors
	 *  (Paypal IPN, 2Checkout, Moneybookers, etc)
	 */
	public function notify()
	{
		$handler = $this->application->getPaymentHandler($this->request->get('id'), $this->getTransaction());
		$orderId = $handler->getOrderIdFromRequest($this->request->toArray());

		$order = CustomerOrder::getInstanceById($orderId, CustomerOrder::LOAD_DATA);
		$order->loadAll();
		$this->order = $order;

		$result = $handler->notify($this->request->toArray());

		if ($result instanceof TransactionResult)
		{
			$this->registerPayment($result, $handler);
		}
		else
		{
			// set error message for credit card form
			$validator = $this->buildCreditCardValidator();
			$validator->triggerError('creditCardError', $result->getMessage());
			$validator->saveState();

			return new ActionRedirectResponse('checkout', 'pay');
		}

		// determine if the notification URL is called by payment gateway or the customer himself
		// this shouldn't usually happen though as the payment notifications should be sent by gateway
		if ($order->user->get() == $this->user)
		{
			$this->request->set('id', $this->order->getID());
			return $this->completeExternal();
		}

		// some payment gateways (2Checkout, for example) require to return HTML response
		// to be displayed after the payment. In this case we're doing meta-redirect to get back to our site.
		else if ($handler->isHtmlResponse())
		{
			$returnUrl = $handler->getReturnUrlFromRequest($this->request->toArray());
			if (!$returnUrl)
			{
				$returnUrl = $this->router->createUrl(array('controller' => 'checkout', 'action' => 'completed', 'query' => array('id' => $this->order->getID())));
			}
			$response = new ActionResponse('order', $order->toArray());
			$response->set('returnUrl', $returnUrl);
			return $response;
		}
	}

	/**
	 *	@role login
	 */
	public function completeExternal()
	{
		SessionOrder::destroy();
		$order = CustomerOrder::getInstanceById($this->request->get('id'), CustomerOrder::LOAD_DATA);
		if ($order->user->get() != $this->user)
		{
			throw new ApplicationException('Invalid order');
		}

		$this->session->set('completedOrderID', $order->getID());
		return new ActionRedirectResponse('checkout', 'completed');
	}

	/**
	 *	@role login
	 */
	public function completed()
	{
		$order = CustomerOrder::getInstanceByID((int)$this->session->get('completedOrderID'), CustomerOrder::LOAD_DATA);
		$order->loadAll();
		$response = new ActionResponse();
		$response->set('order', $order->toArray());
		$response->set('url', $this->router->createUrl(array('controller' => 'user', 'action' => 'viewOrder', 'id' => $this->session->get('completedOrderID')), true));
		return $response;
	}

	public function cvv()
	{
		$this->addBreadCrumb($this->translate('_cvv'), '');

		return new ActionResponse();
	}

	private function registerPayment(TransactionResult $result, TransactionPayment $handler)
	{
		$transaction = Transaction::getNewInstance($this->order, $result);
		$transaction->setHandler($handler);
		$transaction->save();

		return $this->finalizeOrder();
	}

	private function finalizeOrder()
	{
		$newOrder = $this->order->finalize(Currency::getValidInstanceById($this->getRequestCurrency()));

		$orderArray = $this->order->toArray(array('payments' => true));

		// send order confirmation email
		if ($this->config->get('EMAIL_NEW_ORDER'))
		{
			$email = new Email($this->application);
			$email->setUser($this->user);
			$email->setTemplate('order.new');
			$email->set('order', $orderArray);
			$email->send();
		}

		// notify store admin
		if ($this->config->get('NOTIFY_NEW_ORDER'))
		{
			$email = new Email($this->application);
			$email->setTo($this->config->get('NOTIFICATION_EMAIL'), $this->config->get('STORE_NAME'));
			$email->setTemplate('notify.order');
			$email->set('order', $orderArray);
			$email->set('user', $this->user->toArray());
			$email->send();
		}

		$this->session->set('completedOrderID', $this->order->getID());

		SessionOrder::save($newOrder);

		return new ActionRedirectResponse('checkout', 'completed');
	}

	private function getTransaction()
	{
		return new LiveCartTransaction($this->order, Currency::getValidInstanceById($this->getRequestCurrency()));
	}

	/******************************* VALIDATION **********************************/

	/**
	 *	Determines if the necessary steps have been completed, so the order could be finalized
	 *
	 *	@return RedirectResponse
	 *	@return ActionRedirectResponse
	 *	@return false
	 */
	private function validateOrder(CustomerOrder $order, $step = 0)
	{
		// no items in shopping cart
		if (!count($order->getShoppingCartItems()))
		{
			if ($this->request->isValueSet('return'))
			{
				return new RedirectResponse($this->router->createUrlFromRoute($this->request->get('return')));
			}
			else
			{
				return new ActionRedirectResponse('index', 'index');
			}
		}

		// order is not orderable (too few/many items, etc.)
		$isOrderable = $order->isOrderable();
		if (!$isOrderable || $isOrderable instanceof OrderException)
		{
			return new ActionRedirectResponse('order', 'index');
		}

		// shipping address selected
		if ($step >= self::STEP_SHIPPING)
		{
			if ((!$order->shippingAddress->get() && $order->isShippingRequired()) || !$order->billingAddress->get())
			{
				return new ActionRedirectResponse('checkout', 'selectAddress');
			}
		}

		// shipping method selected
		if ($step >= self::STEP_PAYMENT && $order->isShippingRequired())
		{
			foreach ($order->getShipments() as $shipment)
			{
				if (!$shipment->getSelectedRate() && $shipment->isShippable())
				{
					return new ActionRedirectResponse('checkout', 'shipping');
				}
			}
		}

		return false;
	}

	private function validateExpressCheckout()
	{
		if ($redirect = $this->validateOrder($this->order, self::STEP_PAYMENT))
		{
			return $redirect;
		}

		$expressInstance = ExpressCheckout::getInstanceByOrder($this->order);

		if (!$expressInstance)
		{
			return new ActionRedirectResponse('order', 'index');
		}

		try
		{
			$handler = $expressInstance->getTransactionDetails($this->getTransaction());
		}
		catch (PaymentException $e)
		{
			$expressInstance->delete();
			return new ActionRedirectResponse('checkout', 'express', array('id' => $expressInstance->method->get()));
		}

		return $expressInstance;
	}

	private function buildShippingForm(/*ARSet */$shipments)
	{
		ClassLoader::import("framework.request.validator.Form");
		return new Form($this->buildShippingValidator($shipments));
	}

	private function buildShippingValidator(/*ARSet */$shipments)
	{
		ClassLoader::import("framework.request.validator.RequestValidator");
		$validator = new RequestValidator("shipping", $this->request);
		foreach ($shipments as $key => $shipment)
		{
			if ($shipment->isShippable())
			{
				$validator->addCheck('shipping_' . $key, new IsNotEmptyCheck($this->translate('_err_select_shipping')));
			}
		}
		return $validator;
	}

	private function buildAddressSelectorForm(CustomerOrder $order)
	{
		ClassLoader::import("framework.request.validator.Form");
		//$validator = new RequestValidator("addressSelectorValidator_blank", $this->request);
		$validator = $this->buildAddressSelectorValidator($order);
		return new Form($validator);
	}

	private function buildAddressSelectorValidator(CustomerOrder $order)
	{
		ClassLoader::import("framework.request.validator.Form");
		$validator = new RequestValidator("addressSelectorValidator", $this->request);
		$validator->addCheck('billingAddress', new IsNotEmptyCheck($this->translate('_select_billing_address')));

		if ($order->isShippingRequired())
		{
			$validator->addCheck('shippingAddress', new OrCheck(array('shippingAddress', 'sameAsBilling'), array(new IsNotEmptyCheck($this->translate('_select_shipping_address')), new IsNotEmptyCheck('')), $this->request));
		}

		return $validator;
	}

	private function buildCreditCardForm()
	{
		ClassLoader::import("framework.request.validator.Form");
		return new Form($this->buildCreditCardValidator());
	}

	private function buildCreditCardValidator()
	{
		ClassLoader::import("framework.request.validator.RequestValidator");
		$validator = new RequestValidator("creditCard", $this->request);
		$validator->addCheck('ccNum', new IsNotEmptyCheck($this->translate('_err_enter_cc_num')));
//		$validator->addCheck('ccType', new IsNotEmptyCheck($this->translate('_err_select_cc_type')));
		$validator->addCheck('ccExpiryMonth', new IsNotEmptyCheck($this->translate('_err_select_cc_expiry_month')));
		$validator->addCheck('ccExpiryYear', new IsNotEmptyCheck($this->translate('_err_select_cc_expiry_year')));

		if ($this->config->get('REQUIRE_CVV'))
		{
			$validator->addCheck('ccCVV', new IsNotEmptyCheck($this->translate('_err_enter_cc_cvv')));
		}

		$validator->addFilter('ccCVV', new RegexFilter('[^0-9]'));
		$validator->addFilter('ccNum', new RegexFilter('[^ 0-9]'));

		return $validator;
	}
}

?>