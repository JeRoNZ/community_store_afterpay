<?php

namespace Concrete\Package\CommunityStoreAfterpay;

use Concrete\Core\Job\Job;
use Concrete\Core\Package\PackageService;
use Concrete\Core\Support\Facade\Events;
use Concrete\Core\View\View;
use Concrete\Package\CommunityStore\Src\CommunityStore\Cart\Cart;
use Concrete\Package\CommunityStore\Src\CommunityStore\Payment\PaymentEvent;
use Concrete\Package\CommunityStoreAfterpay\Src\CommunityStore\Payment\Methods\CommunityStoreAfterpay\CommunityStoreAfterpayPaymentMethod;
use Concrete\Core\Package\Package;
use Concrete\Core\Support\Facade\Route;
use Whoops\Exception\ErrorException;
use \Concrete\Package\CommunityStore\Src\CommunityStore\Payment\Method as PaymentMethod;

class Controller extends Package {
	protected $pkgHandle = 'community_store_afterpay';
	protected $appVersionRequired = '8.5.0';
	protected $pkgVersion = '0.1';

	protected $pkgAutoloaderRegistries = [
		'src/CommunityStore' => '\Concrete\Package\CommunityStoreAfterpay\Src\CommunityStore'
	];

	public function getPackageDescription () {
		return t('Afterpay Payment Method for Community Store');
	}

	public function getPackageName () {
		return t('Afterpay Payment Method for Community Store');
	}

	public function jobs ($pkg) {
		$jobs = ['afterpay_payment_limits'];
		foreach ($jobs as $handle) {
			$job = Job::getByHandle($handle);
			if (!$job) {
				Job::installByPackage($handle, $pkg);
			}
		}
	}

	public function install () {
		$installed = $this->app->make(PackageService::class)->getInstalledHandles();
		if (!(is_array($installed) && in_array('community_store', $installed))) {
			throw new ErrorException(t('This package requires that Community Store be installed'));
		}

		$pkg = parent::install();
		PaymentMethod::add('community_store_afterpay', 'Afterpay', $pkg);
		$this->jobs($pkg);
	}

	public function uninstall () {
		$pm = PaymentMethod::getByHandle('community_store_afterpay');
		if ($pm) {
			$pm->delete();
		}
		$pkg = parent::uninstall();
	}

	public function on_start () {
		$namespace = CommunityStoreAfterpayPaymentMethod::class;
		Route::register('/checkout/afterpaycreatesession', $namespace . '::createSession');
		Route::register('/checkout/afterpayconfirm', $namespace . '::afterpayConfirm');
		Route::register('/checkout/afterpaycancel', $namespace . '::afterpayCancel');

		Events::addListener('on_page_view', function ($event) {
			$url = 'https://portal.afterpay.com';
			if (\Config::get('community_store_afterpay.SandboxMode')) {
				$url = 'https://portal.sandbox.afterpay.com';
			}
			$view = View::getInstance();
			$view->addHeaderItem('<link rel="dns-prefetch" href="' . $url . '">');
		});

		// Remove Afterpay as a payment method if the cart contains a product in one of the excluded groups
		Events::addListener(PaymentEvent::PAYMENT_ON_AVAILABLE_METHODS_GET, function ($event) {
			$excluded = \Config::get('community_store_afterpay.ExcludedGroups');
			if (!is_array($excluded) || count($excluded) === 0) {
				return;
			}

			$cart = Cart::getCart();
			if (!is_array($cart) || count($cart) === 0) {
				return;
			}

			/** @var $event PaymentEvent */
			$methods = $event->getMethods();
			if (is_array($methods)) {
				foreach ($methods as $k => $method) {
					$cllr = $method->getMethodController();
					if ($cllr instanceof CommunityStoreAfterpayPaymentMethod) {
						foreach ($cart as $item) {
							$groups = $item['product']['object']->getGroupIDs();
							if (is_array($groups)) {
								$matches = array_intersect($excluded, $groups);
								// Matched an excluded prodcut group - remove it from the list and get out
								if (is_array($matches) && count($matches) > 0) {
									unset($methods[$k]);
									$event->setMethods($methods);
									$event->setChanged();

									return;
								}
							}
						}
					}
				}
			}
		});
	}
}