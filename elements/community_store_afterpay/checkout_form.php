<?php defined('C5_EXECUTE') or die(_('Access Denied.'));
extract($vars);

$js = 'https://portal.afterpay.com/afterpay.js';
if (Config::get('community_store_afterpay.SandboxMode')) {
	$js = 'https://portal.sandbox.afterpay.com/afterpay.js';
}
?>


<script>
    $(window).on('load', function() {
        $('.store-btn-complete-order').on('click', function (e) {
            // Open Checkout with further options
            var currentpmid = $('input[name="payment-method"]:checked:first').data('payment-method-id');
            console.log(currentpmid);
            console.log( <?= $pmID; ?>);

            if (currentpmid == <?= $pmID; ?>) {
                $(this).prop('disabled', true);
                $(this).val('<?= t('Processing...'); ?>');

                $.getScript( "<?= $js ?>" )
                    .done(function( script ) {

                        var paymentform = $('#store-checkout-form-group-payment');
                        var data = paymentform.serialize();
                        $.ajax({
                            url: paymentform.attr('action'),
                            type: 'post',
                            cache: false,
                            data: data,
                            dataType: 'text',
                            success: function(data) {
                                $.ajax({
                                    url: '/checkout/afterpaycreatesession',
                                    type: 'get',
                                    cache: false,
                                    dataType: 'text',
                                    success: function(token) {
                                        AfterPay.initialize({countryCode: '<?= Config::get('community_store_afterpay.MerchantCountry') ?>'});
                                        AfterPay.redirect({token: token});
                                    },
                                });

                            }
                        });

                    })
                    .fail(function( jqxhr, settings, exception ) {

                    });


                e.preventDefault();
            }
        });

    });
</script>