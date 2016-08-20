This is an Ubercart payment gateway module for Stripe.

Installation and Setup
======================

a) Use composer to install Stripe per the composer.json provided.

b) Install and enable the module in the normal way for Drupal.

b) Visit your Ubercart Store Administration page, Configuration
section, and enable the gateway under the Payment Gateways.
(admin/store/settings/payment/edit/gateways)

c) On that page, provide the following settings:
   - Your Stripe API key, private

d) Every site dealing with credit cards in any way should be using https. It's
your responsibility to make this happen. (Actually, almost every site should
be https everywhere at this time in the web's history.)

e) If you want Stripe to attempt to validate zip/postal codes, you must enable
that feature on your *Stripe* account settings. Click the checkbox for
"Decline Charges that fail zip code verification" on the "Account info" page.
(You must be collecting billing address information for this to work, of course.)

f) The uc_credit setting "Validate credit card numbers at checkout" must be
disabled on admin/store/settings/payment/method/credit - uc_credit never sees
the credit card number, so cannot properly validate it (and we don't want it to
ever know the credit card number.)

