<?php
defined('C5_EXECUTE') or die(_('Access Denied.'));
extract($vars);
?>
<div class="form-group">
	<?= $form->label('afterpaySandboxEndpointURL', t('Sandbox Endpoint URL')); ?>
	<?= $form->text('afterpaySandboxEndpointURL', ($SandboxEndpointURL ? $SandboxEndpointURL : 'https://global-api-sandbox.afterpay.com')) ?>
</div>

<div class="form-group">
	<?= $form->label('afterpaySandboxMerchantID', t('Sandbox Merchant ID')); ?>
	<?= $form->text('afterpaySandboxMerchantID', $SandboxMerchantID) ?>
</div>

<div class="form-group">
	<?= $form->label('afterpaySandboxMerchantSecretKey', t('Sandbox Merchant Secret Key')); ?>
	<?= $form->text('afterpaySandboxMerchantSecretKey', $SandboxMerchantSecretKey) ?>
</div>


<div class="form-group">
	<?= $form->checkbox('afterpaySandboxMode', '1', $SandboxMode) ?>
	<?= $form->label('afterpaySandboxMode', t('Sandbox Mode')) ?>
</div>

<div class="form-group">
	<?= $form->label('afterpayLiveEndpointURL', t('Live Endpoint URL')); ?>
	<?= $form->text('afterpayLiveEndpointURL', ($LiveEndpointURL ? $LiveEndpointURL : 'https://global-api.afterpay.com')) ?>
</div>
<div class="form-group">
	<?= $form->label('afterpayLiveMerchantID', t('Live Merchant ID')); ?>
	<?= $form->text('afterpayLiveMerchantID', $LiveMerchantID) ?>
</div>
<div class="form-group">
	<?= $form->label('afterpayLiveMerchantSecretKey', t('Live Merchant Secret Key')); ?>
	<?= $form->text('afterpayLiveMerchantSecretKey', $LiveMerchantSecretKey) ?>
</div>

<div class="form-group">
	<?= $form->label('afterpayMerchantCountry', t('Merchant Country')); ?>
	<?= $form->select('afterpayMerchantCountry',
        ['auto' => 'Auto',
		'AU' => 'Australia',
		'CA' => 'Canada',
		'NZ' => 'New Zealand',
		'US' => 'United States'],$MerchantCountry) ?>
</div>

<div class="form-group">
	<?= $form->label('afterpayMaxAttempts', t('Maximum checkout attempts')); ?>
	<?= $form->number('afterpayMaxAttempts', ($MaxAttempts ?: 20), ['min' => 5, 'max' => 100, 'step' => '1']) ?>
</div>


<div class="form-group">
	<?= $form->checkbox('afterpayDebug', '1', $Debug) ?>
	<?= $form->label('afterpayDebug', t('Debug logging')) ?>
</div>