# Community Store Afterpay/Clearpay
Afterpay/Clearpay payments for Community Store for concrete5 / Concrete CMS

Install [Community Store](https://github.com/concrete5-community-store/community_store) First.

You will need to register an account with [Afterpay](https://www.afterpay.com)

## Afterpay Widget
To show the Afterpay widget on your product pages you will need to modify your product templates to include the following code snippet just below the price(s) are shown to the user:
```
<afterpay-placement
   data-locale="<?= Localization::activeLocale() ?>"
   data-currency="<?= Config::get('community_store_afterpay.currency') ?>"
   data-amount="<?= number_format($price,'2','.','')?>">
</afterpay-placement>
```

The required JavaScript is loaded by the package controller.