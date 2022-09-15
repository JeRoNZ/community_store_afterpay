<?php

namespace Concrete\Package\CommunityStoreAfterpay\Src\CommunityStore\Payment\Methods\CommunityStoreAfterpay;

/*
 * Author: Jeremy Rogers infoatjero.co.nz
 * License: MIT
 */

use Concrete\Core\Error\ErrorList\ErrorList;
use Concrete\Core\Http\Response;
use Concrete\Core\Multilingual\Page\Section\Section;
use Concrete\Core\Package\Package;
use Concrete\Core\Package\PackageService;
use Concrete\Core\Support\Facade\Application;
use Concrete\Core\Support\Facade\Session;
use Concrete\Package\CommunityStore\Src\CommunityStore\Customer\Customer as StoreCustomer;
use Concrete\Package\CommunityStore\Src\CommunityStore\Group\GroupList as StoreGroupList;
use Concrete\Package\CommunityStore\Src\CommunityStore\Order\Order;
use Concrete\Package\CommunityStore\Src\CommunityStore\Order\OrderItem;
use Core;
use GuzzleHttp\Client;
use Symfony\Component\HttpFoundation\RedirectResponse;
use URL;
use Config;

use \Concrete\Package\CommunityStore\Src\CommunityStore\Payment\Method as StorePaymentMethod;
use \Concrete\Package\CommunityStore\Src\CommunityStore\Order\Order as StoreOrder;
use Concrete\Core\Logging\LoggerFactory;

class CommunityStoreAfterpayPaymentMethod extends StorePaymentMethod {
	/* @var $logger \Monolog\Logger */
	private $logger;


	public function afterpayConfirm () {
		$status = $this->request->get('status');
		$orderToken = $this->request->get('orderToken');
		$error = new ErrorList();
		if ($status !== 'SUCCESS') {
			$error->add(t('Your payment was declined'));
			$this->log(t('Payment for token %s with status %s', $orderToken, $status));
		} else {
			if (!$orderToken) { // Just get out if there's no token
				$this->log(t('afterpayConfirm: No order token present, bailing out'), true);

				return new RedirectResponse('/');
			}

			$client = new Client();
			// Immediate payment flow
			// https://developers.afterpay.com/afterpay-online/reference/capture-full-payment
			try {
				$endpoint = '/v2/payments/capture';
				if (Config::get('community_store_afterpay.PaymentFlow') === 1) {
					$endpoint = '/v2/payments/auth';
				}
				$this->log(t('Initiating payment for token %s using %s', $orderToken, $endpoint));
				$response = $client->request('POST', $this->getURL() . $endpoint, [
					'headers' => $this->getHeaders(),
					'body' => json_encode(['token' => $orderToken])
				]);
				$json = $response->getBody()->getContents();
				$payment = json_decode($json);
				if ($payment->status === 'APPROVED') {
					$orderID = (int) $payment->merchantReference;
					if ($orderID) {
						/** @var Order $order */
						$order = Order::getByID($orderID);
						if ($order) {
							if ($order->getTransactionReference()) {
								$this->log(t('Not completing payment for order %s because paymentID is already set: %s', $orderID, $order->getTransactionReference()));
							} else {
								$this->log(t('Completing payment for order %s paymentID %s', $orderID, $payment->id));
								$order->completeOrder($payment->id);
							}

							return new RedirectResponse($this->getLangPath() . '/checkout/complete');
						}
					}
					$error->add(t('Could not find your order'));
					$this->log(t('Could not get order for token %s, orderID %s, response %s', $orderToken, $payment->merchantReference, $json), true);
				} else {
					$error->add(t('Your payment was declined'));
					$this->log(t('Payment for token %s was declined', $orderToken));
				}
			} catch (\Exception $e) {
				$this->log(t('Unable to process payment: ').$e->getMessage(), true);
				$error->add(t('Unable to process payment'));
			}
		}

		$this->flash('error', $error);

		return new RedirectResponse($this->getLangPath() . '/checkout');
	}


	public function afterpayCancel () {
		$orderToken = $this->request->get('orderToken');
		$this->log(t('afterpayCancel for token %s', $orderToken));

		$this->flash('message', t('Your payment was cancelled'));

		return new RedirectResponse($this->getLangPath() . '/checkout');
	}


	public function createSession () {
		// fetch order just submitted
		/** @var Order $order */
		$orderID = Session::get('orderID');

		$error = new ErrorList();
		try {
			$order = StoreOrder::getByID($orderID);
		} catch (\Exception $e){
			$this->log(t('Error retrieving order %s, %s', var_export($orderID, true), $e->getMessage()), true);

			$error->add(t("An error occurred initiating your payment.\nYour order was not found"));
			$this->flash('error', $error);

			return new Response(t("An error occurred initiating your payment.\nYour order was not found"), 500);
		}
		$currency = Config::get('community_store_afterpay.currency');

		if ($order) {
			$this->log(t('Generating afterpay payload for orderID %s', $orderID));
			$goodsTotal = 0;
			$orderItems = [];
			/** @var OrderItem[] $items */
			$items = $order->getOrderItems();
			if ($items) {
				foreach ($items as $item) {
					$goodsTotal += round($item->getPricePaid(), 2) * $item->getQuantity();
					$imagesrc = '';
					$fileObj = $item->getProductObject()->getImageObj();
					if (is_object($fileObj)) {
						$imagesrc = $fileObj->getURL();
					}

					$optionOutput = [$item->getProductName()];
					$options = $item->getProductOptions();
					if ($options) {
						$optionOutput = [];
						foreach ($options as $option) {
							if ($option['oioValue']) {
								$optionOutput[] = $option['oioKey'] . ": " . $option['oioValue'];
							}
						}
					}
					$optionText = implode(' ', $optionOutput);

					$orderItems[] = [
						'name' => substr($optionText, 0, 255),
						'sku' => substr($item->getSKU(), 0, 128),
						'quantity' => (int) $item->getQuantity(),
						'imageUrl' => substr($imagesrc, 0, 2048),
						'price' => [
							'amount' => number_format(round($item->getPricePaid(), 2), 2, '.', ''),
							'currency' => $currency
						]
					];
				}
			} else {
				$this->log(t('No items found on orderID %s', $orderID), true);
				$error->add('Your cart is empty');
				$this->flash('error', $error);

				return new Response(t('Your cart is empty'), 401);
			}

			$shippingAmount = 0;
			if ($order->isShippable()) {
				$shippingAmount = round($order->getShippingTotal(), 2);
			}

			$taxes = $order->getTaxes();

			$taxAmount = 0;
			if (!empty($taxes)) {
				foreach ($order->getTaxes() as $tax) {
					if ($tax['amount']) {
						$taxAmount += round($tax['amount'], 2);
					} elseif ($tax['amountIncluded']) {
						$taxAmount += round($tax['amountIncluded'], 2);
					}
				}
			}

			$customer = new StoreCustomer();

			$shipping = [
				'name' => substr($customer->getValue('shipping_first_name') . ' ' . $customer->getValue('shipping_last_name'), 0, 255),
				'line1' => substr($customer->getValue('shipping_address')->address1, 0, 128),
				'line2' => substr($customer->getValue('shipping_address')->address2, 0, 128),
				'area1' => substr($customer->getValue('shipping_address')->city, 0, 128),
				'region' => substr($customer->getValue('shipping_address')->state_province, 0, 128),
				'postcode' => substr($customer->getValue('shipping_address')->postal_code, 0, 128),
				// https://developers.afterpay.com/afterpay-online/reference/create-checkout-1
				// The two-character ISO 3166-1 country code.
				'countryCode' => $customer->getValue('shipping_address')->country
			];

			$billing = [
				'name' => substr($customer->getValue('billing_first_name') . ' ' . $customer->getValue('billing_last_name'), 0, 255),
				'line1' => substr($customer->getValue('billing_address')->address1, 0, 128),
				'line2' => substr($customer->getValue('billing_address')->address2, 0, 128),
				'area1' => substr($customer->getValue('billing_address')->city, 0, 128),
				'region' => substr($customer->getValue('billing_address')->state_province, 0, 128),
				'postcode' => substr($customer->getValue('billing_address')->postal_code, 0, 128),
				'countryCode' => $customer->getValue('billing_address')->country,
			];

			$merchant = [
				'redirectConfirmUrl' => (string) \URL::to('/checkout/afterpayconfirm'),
				'redirectCancelUrl' => (string) \URL::to('/checkout/afterpaycancel'),
				'popupOrginUrl' => (string) \URL::to($this->getLangPath() . '/cart')
			];

			$consumer = [
				'phoneNumber' => substr($customer->getValue('billing_phone'), 0, 32),
				'givenNames' => substr($customer->getValue('billing_first_name'), 0, 128),
				'surname' => substr($customer->getValue('billing_last_name'), 0, 128),
				'email' => substr($customer->getEmail(), 0, 128),
			];

			$data = [
				'items' => $orderItems,
				'amount' => ['amount' => number_format($goodsTotal, 2, '.', ''), 'currency' => $currency],
				'consumer' => $consumer,
				'billing' => $billing,
				'shipping' => $shipping,
				'merchant' => $merchant,
				'merchantReference' => (int) $order->getOrderID(),
				'taxAmount' => ['amount' => number_format($taxAmount, 2, '.', ''), 'currency' => $currency],
				'shippingAmount' => ['amount' => number_format($shippingAmount, 2, '.', ''), 'currency' => $currency],
			];

			$body = json_encode($data);
			$this->log(t('Requesting afterpay token for orderID %s with payload %s', $orderID, $body));

			$client = new Client();
			try {
				$response = $client->request('POST', $this->getURL() . '/v2/checkouts', [
					'headers' => $this->getHeaders(),
					'body' => $body
				]);
				$json = $response->getBody()->getContents();

				$payload = json_decode($json);
				$token = $payload->token;

				return new Response($token, 200);

			} catch (\Exception $e) {
				$this->log($e->getMessage(), true);

				$error->add(t('An error occurred initiating your payment. Please try another payment method'));
				$this->flash('error', $error);

				return new Response(t("An error occurred initiating your payment.\nPlease try another payment method"), 500);
			}
		}

		$this->log(t('Could not find an order for the session id %s', var_export($orderID, true)), true);
		$error->add('Error - no order found');
		$this->flash('error', $error);

		return new Response(t('Error - no order found'), 401);
	}


	public function submitPayment () {
		//nothing to do except return true
		return ['error' => 0, 'transactionReference' => ''];
	}


	public function dashboardForm () {
		$this->set('LivetEndpointURL', Config::get('community_store_afterpay.LiveEndpointURL'));
		$this->set('LiveMerchantID', Config::get('community_store_afterpay.LiveMerchantID'));
		$this->set('LiveMerchantSecretKey', Config::get('community_store_afterpay.LiveMerchantSecretKey'));
		$this->set('SandboxMode', Config::get('community_store_afterpay.SandboxMode'));
		$this->set('SandboxEndpointURL', Config::get('community_store_afterpay.SandboxEndpointURL'));
		$this->set('SandboxMerchantID', Config::get('community_store_afterpay.SandboxMerchantID'));
		$this->set('SandboxMerchantSecretKey', Config::get('community_store_afterpay.SandboxMerchantSecretKey'));
		$this->set('Debug', Config::get('community_store_afterpay.Debug'));
		$this->set('MerchantCountry', Config::get('community_store_afterpay.MerchantCountry'));
		$this->set('PaymentFlow', Config::get('community_store_afterpay.PaymentFlow'));

		$grouplist = StoreGroupList::getGroupList();
		$this->set('grouplist', $grouplist);
		foreach ($grouplist as $productgroup) {
			$productgroups[$productgroup->getGroupID()] = $productgroup->getGroupName();
		}
		$this->set('productgroups', $productgroups);
		$this->set('pgroups', Config::get('community_store_afterpay.ExcludedGroups') ?: []);
		$this->requireAsset('css', 'select2');
		$this->requireAsset('javascript', 'select2');

		$app = Application::getFacadeApplication();
		$this->set('form', $app->make('helper/form'));
	}


	public function save (array $data = []) {
		Config::save('community_store_afterpay.SandboxEndpointURL', $data['afterpaySandboxEndpointURL']);
		Config::save('community_store_afterpay.SandboxMerchantID', $data['afterpaySandboxMerchantID']);
		Config::save('community_store_afterpay.SandboxMerchantSecretKey', $data['afterpaySandboxMerchantSecretKey']);
		Config::save('community_store_afterpay.Debug', ($data['afterpayDebug'] ? 1 : 0));
		Config::save('community_store_afterpay.LiveEndpointURL', $data['afterpayLiveEndpointURL']);
		Config::save('community_store_afterpay.LiveMerchantID', $data['afterpayLiveMerchantID']);
		Config::save('community_store_afterpay.LiveMerchantSecretKey', $data['afterpayLiveMerchantSecretKey']);
		Config::save('community_store_afterpay.SandboxMode', ($data['afterpaySandboxMode'] ? 1 : 0));
		Config::save('community_store_afterpay.MerchantCountry', ($data['afterpayMerchantCountry'] ?: 'auto'));
		Config::save('community_store_afterpay.PaymentFlow', ($data['afterpayPaymentFlow'] ? 1 : 0));

		$excludedGroups = [];
		if (is_array($data['afterpayExcludedGroups'])) {
			$excludedGroups = $data['afterpayExcludedGroups'];
		}
		Config::save('community_store_afterpay.ExcludedGroups', $excludedGroups);

		$pmID = array_search('community_store_afterpay', $data['paymentMethodHandle']);
		// Need to get the payment limits via API and store server side
		if ($data['paymentMethodEnabled'][$pmID] === '1') {
			$this->setPaymentLimits();
		}

	}


	public function getName () {
		return 'Afterpay';
	}


	public function isExternal () {
		return true;
	}


	public function getPaymentMinimum() {
		return Config::get('community_store_afterpay.minimumAmount');
	}


	public function getPaymentMaximum() {
		return Config::get('community_store_afterpay.maximumAmount');
	}


	public function setPaymentLimits () {
		$client = new Client();

		try {
			$response = $client->request('GET', $this->getURL() . '/v2/configuration', [
				'headers' => $this->getHeaders()
			]);

			$json = json_decode($response->getBody()->getContents());
			Config::save('community_store_afterpay.minimumAmount', $json->minimumAmount->amount);
			Config::save('community_store_afterpay.maximumAmount', $json->maximumAmount->amount);
			Config::save('community_store_afterpay.currency', $json->minimumAmount->currency);
		} catch (\Exception $e) {
			$error = new ErrorList();
			$error->add($e->getMessage());
			$this->flash('error', $error);
		}
	}


	public function validate ($args, $e) {
		$pm = StorePaymentMethod::getByHandle('community_store_afterpay');
		if ($args['paymentMethodEnabled'][$pm->getID()] == 1) {
			if ($args['afterpaySandboxMode']) {
				if ($args['afterpaySandboxMerchantID'] === '') {
					$e->add(t('Sandbox Merchant ID must be set'));
				}
				if ($args['afterpaySandboxMerchantSecretKey'] === '') {
					$e->add(t('Sandbox Merchant Secret Key must be set'));
				}
				if ($args['afterpaySandboxEndpointURL'] === '') {
					$e->add(t('Sandbox Endpoint URL must be set'));
				}
			} else {
				if ($args['afterpayLiveMerchantID'] === '') {
					$e->add(t('Live Merchant ID must be set'));
				}
				if ($args['afterpayLiveMerchantSecretKey'] === '') {
					$e->add(t('Live Merchant Secret Key must be set'));
				}
				if ($args['afterpayLiveEndpointURL'] === '') {
					$e->add(t('Live Endpoint URL must be set'));
				}
			}
		}

		return $e;

	}


	public function checkoutForm () {
		$pmID = StorePaymentMethod::getByHandle('community_store_afterpay')->getID();
		$this->set('pmID', $pmID);
	}


	private function getLangPath () {
		$referrer = $this->request->server->get('HTTP_REFERER');;
		$c = \Page::getByPath(parse_url($referrer, PHP_URL_PATH));
		$al = Section::getBySectionOfSite($c);
		$langpath = '';
		if ($al !== null) {
			$langpath = $al->getCollectionHandle();
		}

		return $langpath;
	}


	private function log ($message, $force = false) {
		if (!$force) {
			if (!Config::get('community_store_afterpay.Debug')) {
				return false;
			}
		}
		if (!$this->logger) {
			$app = Application::getFacadeApplication();
			$this->logger = $app->make(LoggerFactory::class)->createLogger('afterpay');
		}
		if ($force) {
			$this->logger->addError($message);
		} else {
			$this->logger->addDebug($message);
		}

		return true;

	}


	private function getURL () {
		if (Config::get('community_store_afterpay.SandboxMode')) {
			$url = Config::get('community_store_afterpay.SandboxEndpointURL');
		} else {
			$url = Config::get('community_store_afterpay.LiveEndpointURL');
		}

		// Remove trailing / if it's there, so we do not end up with two later
		return trim($url, '/');
	}


	private function getMerchantID () {
		return Config::get('community_store_afterpay.SandboxMode') ? Config::get('community_store_afterpay.SandboxMerchantID') : Config::get('community_store_afterpay.LiveMerchantID');
	}


	private function getHeaders(){
		return [
			'Accept' => 'application/json',
			'Content-Type' => 'application/json',
			'Authorization' => 'Basic ' . $this->getAuth(),
			'User-Agent' => $this->getUserAgent(),
		];
	}


	private function getUserAgent () {
		$app = Application::getFacadeApplication();
		// User agent is required
		// https://developers.afterpay.com/afterpay-online/reference/user-agent-header
		// MyAfterpayModule/1.0.0 (E-Commerce Platform Name/1.0.0; PHP/7.0.0; Merchant/600032000) https://merchant.example.com

		/* @var $cs Package */
		/* @var $ap Package */
		$cs = $app->make(PackageService::class)->getByHandle('community_store');
		$ap = $app->make(PackageService::class)->getByHandle('community_store_afterpay');

		$ua = [
			'CommunityStoreAfterpay/' . $ap->getPackageVersion(),
			'(Concrete CMS Community Store/' . $cs->getPackageVersion() . ';',
			'PHP/' . phpversion() . ';',
			'Merchant/' . $this->getMerchantID() . ')',
			(string) \URL::to('/')
		];

		return implode(' ', $ua);
	}


	private function getAuth () {
		if (Config::get('community_store_afterpay.SandboxMode')) {
			return base64_encode(Config::get('community_store_afterpay.SandboxMerchantID') . ':' . Config::get('community_store_afterpay.SandboxMerchantSecretKey'));
		}

		return base64_encode(Config::get('community_store_afterpay.LiveMerchantID') . ':' . Config::get('community_store_afterpay.LiveMerchantSecretKey'));
	}
}