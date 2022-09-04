<?php

namespace Concrete\Package\CommunityStoreAfterpay\Job;

use \Concrete\Core\Job\Job as AbstractJob;
use Concrete\Package\CommunityStore\Src\CommunityStore\Payment\Method;

class AfterpayPaymentLimits extends AbstractJob {
	public function getJobName () {
		return t('Afterpay Payment Limits');
	}

	public function getJobDescription () {
		return t('Retrieve Afterpay payment Limits');
	}

	public function run () {
		$method = Method::getByHandle('community_store_afterpay');
		if ($method) {
			$method->getMethodController()->setPaymentLimits();

			return t('Payment limits updated');

		}

		return t('Error');
	}
}