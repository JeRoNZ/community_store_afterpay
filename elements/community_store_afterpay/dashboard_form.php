<?php
defined('C5_EXECUTE') or die(_('Access Denied.'));
extract($vars);
?>


<div class="row">
    <div class="col-md-6">
        <div class="form-group">
			<?= $form->label('afterpaySandboxEndpointURL', t('Sandbox Endpoint URL')); ?>
			<?= $form->text('afterpaySandboxEndpointURL', ($SandboxEndpointURL ? $SandboxEndpointURL : 'https://global-api-sandbox.afterpay.com')) ?>
        </div>
    </div>
    <div class="col-md-6">
        <div class="form-group">
			<?= $form->label('afterpaySandboxMerchantID', t('Sandbox Merchant ID')); ?>
			<?= $form->text('afterpaySandboxMerchantID', $SandboxMerchantID) ?>
        </div>
    </div>
</div>


<div class="form-group">
	<?= $form->label('afterpaySandboxMerchantSecretKey', t('Sandbox Merchant Secret Key')); ?>
	<?= $form->text('afterpaySandboxMerchantSecretKey', $SandboxMerchantSecretKey) ?>
</div>


<div class="row">
    <div class="col-md-6">
        <div class="form-group">
			<?= $form->checkbox('afterpaySandboxMode', '1', $SandboxMode) ?>
			<?= $form->label('afterpaySandboxMode', t('Sandbox Mode')) ?>
        </div>
    </div>
    <div class="col-md-6">
        <div class="form-group">
			<?= $form->checkbox('afterpayDebug', '1', $Debug) ?>
			<?= $form->label('afterpayDebug', t('Debug logging')) ?>
        </div>
    </div>
</div>


<div class="row">
    <div class="col-md-6">
        <div class="form-group">
			<?= $form->label('afterpayLiveEndpointURL', t('Live Endpoint URL')); ?>
			<?= $form->text('afterpayLiveEndpointURL', ($LiveEndpointURL ? $LiveEndpointURL : 'https://global-api.afterpay.com')) ?>
        </div>
    </div>
    <div class="col-md-6">
        <div class="form-group">
			<?= $form->label('afterpayLiveMerchantID', t('Live Merchant ID')); ?>
			<?= $form->text('afterpayLiveMerchantID', $LiveMerchantID) ?>
        </div>
    </div>
</div>


<div class="form-group">
	<?= $form->label('afterpayLiveMerchantSecretKey', t('Live Merchant Secret Key')); ?>
	<?= $form->text('afterpayLiveMerchantSecretKey', $LiveMerchantSecretKey) ?>
</div>


<div class="row">
    <div class="col-md-6">
        <div class="form-group">
			<?= $form->label('afterpayMerchantCountry', t('Merchant Country')); ?>
			<?= $form->select('afterpayMerchantCountry',
				[
					'NZ' => 'New Zealand',
					'AU' => 'Australia',
					'CA' => 'Canada',
					'US' => 'United States'
				], $MerchantCountry) ?>
        </div>
    </div>
    <div class="col-md-6">
        <div class="form-group">
			<?= $form->label('afterpayPaymentFlow', t('Payment Flow')); ?>
			<?= $form->select('afterpayPaymentFlow', [0 => 'Immediate Payment', 1 => 'Deferred Payment'], $PaymentFlow) ?>
        </div>
    </div>
</div>

<?= $form->label('', t('Excluded product groups')); ?>
<div class="form-group">
    <select multiple="multiple" name="afterpayExcludedGroups[]" class="select2-select afterpayExcludedGroups" style="width: 100%"
            placeholder="<?= (empty($productgroups) ? t('No Product Groups Available') : t('Select Product Groups')); ?>">
		<?php
		if (!empty($productgroups)) {
			if (!is_array($pgroups)) {
				$pgroups = [];
			}
			foreach ($productgroups as $pgkey => $pglabel) { ?>
                <option value="<?= $pgkey; ?>" <?= (in_array($pgkey, $pgroups) ? 'selected="selected"' : ''); ?>>  <?= $pglabel; ?></option>
			<?php }
		} ?>
    </select>
</div>

<script>
    $(document).ready(function () {
        $('.afterpayExcludedGroups').select2();
    });
</script>