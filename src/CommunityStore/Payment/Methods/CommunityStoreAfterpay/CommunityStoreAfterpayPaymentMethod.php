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
use Concrete\Package\CommunityStore\Src\CommunityStore\Order\Order;
use Concrete\Package\CommunityStore\Src\CommunityStore\Utilities\Price as StorePrice;
use Core;
use GuzzleHttp\Client;
use IPLib\Address\AddressInterface;
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
		} else {
			if (!$orderToken) { // Just get out if there's no token
				return new RedirectResponse('/');
			}

			$client = new Client();
			// Immediate payment flow
			// https://developers.afterpay.com/afterpay-online/reference/capture-full-payment
			try {
				$response = $client->request('POST', $this->getURL() . '/v2/payments/capture', [
					'headers' => $this->getHeaders(),
					'body' => json_encode(['token' => $orderToken])
				]);
				$json = $response->getBody()->getContents();
				$payment = json_decode($json);
				if ($payment->status === 'APPROVED') {
					// WEB000001
					$orderID = (int) substr($payment->merchantReference, 3);
					if ($orderID) {
						/** @var Order $order */
						$order = Order::getByID($orderID);
						if ($order) {
							$order->completeOrder($payment->id);

							return new RedirectResponse($this->getLangPath() . '/checkout/complete');
						}
					}
					$error->add(t('Could not find your order'));
				} else {
					$error->add(t('Your payment was declined'));
				}
			} catch (\Exception $e) {
				$this->log($e->getMessage(), true);
				$error->add(t('Unable to process payment'));
			}
		}

		$this->flash('error', $error);

		return new RedirectResponse($this->getLangPath() . '/checkout');
	}


	public function afterpayCancel () {
		$this->flash('message', t('Your payment was cancelled'));

		return new RedirectResponse($this->getLangPath() . '/checkout');
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

	public function createSession () {
		$referrer = $this->request->server->get('HTTP_REFERER');;
		$c = \Page::getByPath(parse_url($referrer, PHP_URL_PATH));
		$al = Section::getBySectionOfSite($c);
		$langpath = '';
		if ($al !== null) {
			$langpath = $al->getCollectionHandle();
		}

		// fetch order just submitted
		/** @var Order $order */
		$order = StoreOrder::getByID(Session::get('orderID'));
		$currency = Config::get('community_store_stripe_checkout.currency'); // TODO
		$this->set('currency', $currency);

//		$currencyMultiplier = StorePrice::getCurrencyMultiplier($currency);
		$currencyMultiplier = 1;

		if ($order) {
			$goodsTotal = 0;
			$items = $order->getOrderItems();
			if ($items) {
				foreach ($items as $item) {
					$goodsTotal += round($item->getPricePaid() * $currencyMultiplier, 0) * $item->getQty();
				}
			}

			$shippingAmount = 0;
			if ($order->isShippable()) {
				$shippingAmount = round($order->getShippingTotal() * $currencyMultiplier, 0);
			}

			$taxes = $order->getTaxes();

			$taxAmount = 0;
			if (!empty($taxes)) {
				foreach ($order->getTaxes() as $tax) {
					if ($tax['amount']) {
						$taxAmount += round($tax['amount'] * $currencyMultiplier, 0);
					}
				}
			}

			$customer = new StoreCustomer();

			$shipping = [
				'name' => $customer->getValue('shipping_first_name'),
				'line1' => $customer->getValue('shipping_address')->address1,
				'postcode' => $customer->getValue('shipping_address')->postal_code,
			];

			$billing = [
				'name' => $customer->getValue('billing_first_name'),
				'line1' => $customer->getValue('billing_address')->address1,
				'postcode' => $customer->getValue('billing_address')->postal_code,
			];

			$merchant = [
				'redirectConfirmUrl' => (string) \URL::to($langpath . '/checkout/afterpayconfirm'),
				'redirectCancelUrl' => (string) \URL::to($langpath . '/checkout/afterpaycancel'),
				'popupOrginUrl' => (string) \URL::to($langpath . '/cart')
			];

			$consumer = [
				'givenNames' => $customer->getValue('billing_first_name'),
				'surname' => $customer->getValue('billing_last_name'),
				'email' => $customer->getEmail()
			];

			$data = [
				'amount' => ['amount' => $goodsTotal, 'currency' => $currency],
				'consumer' => $consumer,
				'billing' => $billing,
				'shipping' => $shipping,
				'merchant' => $merchant,
				'merchantReference' => 'WEB' . sprintf('%06d', $order->getOrderID()),
				'taxAmount' => ['amount' => $taxAmount, 'currency' => $currency],
				'shippingAmount' => ['amount' => $shippingAmount, 'currency' => $currency],
			];

			$body = json_encode($data);

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

				return new Response(t('An error occurred initiating your payment. Please try another payment method'), 500);
			}
		}

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
		$this->set('currency', Config::get('community_store_afterpay.currency'));
		$this->set('MaxAttempts', Config::get('community_store_afterpay.MaxAttempts'));
		$this->set('Debug', Config::get('community_store_afterpay.Debug'));
		$this->set('MerchantCountry', Config::get('community_store_afterpay.MerchantCountry'));


		$currencies = array(
//			'AUD' => 'Australian Dollar',
			'NZD' => 'New Zealand Dollar',
		);
		$this->set('currencies', $currencies);
		$app = Application::getFacadeApplication();
		$this->set('form', $app->make('helper/form'));
	}


	public function getName () {
		return 'Afterpay';
	}


	public function isExternal () {
		return true;
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
		Config::save('community_store_afterpay.MaxAttempts', ($data['afterpayMaxAttempts'] ? (int) $data['afterpayMaxAttempts'] : 20));
		Config::save('community_store_afterpay.MerchantCountry', ($data['afterpayMerchantCountry'] ?: 'auto'));
// TODO excluded product groups....

		// Need to get the payment limits via API and store server side
		$this->setPaymentLimits();

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
//		$this->set('currency', Config::get('community_store_stripe_checkout.currency')); TODO
		$pmID = StorePaymentMethod::getByHandle('community_store_afterpay')->getID();
		$this->set('pmID', $pmID);
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