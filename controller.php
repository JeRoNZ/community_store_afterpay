<?php

namespace Concrete\Package\CommunityStoreAfterpay;

use Concrete\Package\CommunityStoreAfterpay\Src\CommunityStore\Payment\Methods\CommunityStoreAfterpay\CommunityStoreAfterpayPaymentMethod;
use Package;
use Route;
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

	public function install () {
		$installed = Package::getInstalledHandles();
		if (!(is_array($installed) && in_array('community_store', $installed))) {
			throw new ErrorException(t('This package requires that Community Store be installed'));
		} else {
			$pkg = parent::install();
			PaymentMethod::add('community_store_afterpay', 'Afterpay', $pkg);
		}

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
		Route::register('/checkout/afterpayCancel', $namespace . '::afterpayCancel');
	}
}