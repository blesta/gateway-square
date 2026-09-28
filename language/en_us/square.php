<?php

$lang['Square.name'] = 'Square';
$lang['Square.description'] = 'Square, Inc. is a financial services, merchant services aggregator, and mobile payment company based in San Francisco, California. The have a strong consumer base in small businesses';
$lang['Square.application_id'] = 'Application ID';
$lang['Square.access_token'] = 'Access Token';
$lang['Square.location_id'] = 'Location ID';
$lang['Square.webhook_signature_key'] = 'Webhook Signature Key (optional)';
$lang['Square.webhook'] = 'Square Webhook';
$lang['Square.webhook_note'] = 'Optional. To verify payments even if the customer never returns to this site, subscribe a webhook in Square\'s dashboard to the payment.created and payment.updated events using the following url, then paste its Signature Key above. Leave the Signature Key blank to keep using redirect-only verification.';
$lang['Square.buildprocess.submit'] = 'Pay with Square';

// Errors
$lang['Square.!error.application_id.valid'] = 'You must enter a valid Application ID.';
$lang['Square.!error.access_token.valid'] = 'You must enter a valid Access Token.';
$lang['Square.!error.location_id.valid'] = 'You must enter a valid Location ID.';
$lang['Square.!error.webhook.signature'] = 'The webhook request signature could not be verified.';
$lang['Square.!error.webhook.payment'] = 'The payment referenced by the webhook could not be retrieved.';
$lang['Square.!error.webhook.order'] = 'The order referenced by the webhook payment could not be retrieved.';

// Tooltips
$lang['Square.!tooltip.webhook_signature_key'] = 'Only required if you subscribe a webhook to this gateway in Square\'s dashboard. Leave blank to rely solely on redirect-based verification.';
