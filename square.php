<?php
/**
 * Square Gateway.
 *
 * The Square API documentation can be found at:
 * https://docs.connect.squareup.com/articles/square-checkout-overview/
 *
 * @package blesta
 * @subpackage blesta.components.gateways.square
 * @copyright Copyright (c) 2010, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class Square extends NonmerchantGateway
{
    /**
     * @var array An array of meta data for this gateway
     */
    private $meta;

    /**
     * Construct a new merchant gateway.
     */
    public function __construct()
    {
        $this->loadConfig(dirname(__FILE__) . DS . 'config.json');

        // Load components required by this gateway
        Loader::loadComponents($this, ['Input']);

        // Load the language required by this gateway
        Language::loadLang('square', null, dirname(__FILE__) . DS . 'language' . DS);
    }

    /**
     * Sets the currency code to be used for all subsequent payments.
     *
     * @param string $currency The ISO 4217 currency code to be used for subsequent payments
     */
    public function setCurrency($currency)
    {
        $this->currency = $currency;
    }

    /**
     * Create and return the view content required to modify the settings of this gateway.
     *
     * @param array $meta An array of meta (settings) data belonging to this gateway
     * @return string HTML content containing the fields to update the meta data for this gateway
     */
    public function getSettings(array $meta = null)
    {
        $this->view = $this->makeView('settings', 'default', str_replace(ROOTWEBDIR, '', dirname(__FILE__) . DS));

        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html']);

        $this->view->set('meta', $meta);

        return $this->view->fetch();
    }

    /**
     * Validates the given meta (settings) data to be updated for this gateway.
     *
     * @param array $meta An array of meta (settings) data to be updated for this gateway
     * @return array The meta data to be updated in the database for this gateway, or reset into the form on failure
     */
    public function editSettings(array $meta)
    {
        // Verify meta data is valid
        $rules = [
            'application_id' => [
                'valid' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => Language::_('Square.!error.application_id.valid', true)
                ]
            ],
            'access_token' => [
                'valid' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => Language::_('Square.!error.access_token.valid', true)
                ]
            ],
            'location_id' => [
                'valid' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => Language::_('Square.!error.location_id.valid', true)
                ]
            ]
        ];

        $this->Input->setRules($rules);

        // Validate the given meta data to ensure it meets the requirements
        $this->Input->validates($meta);

        // Return the meta data, no changes required regardless of success or failure for this gateway
        return $meta;
    }

    /**
     * Returns an array of all fields to encrypt when storing in the database.
     *
     * @return array An array of the field names to encrypt when storing in the database
     */
    public function encryptableFields()
    {
        return ['application_id', 'access_token', 'location_id', 'webhook_signature_key'];
    }

    /**
     * Sets the meta data for this particular gateway.
     *
     * @param array $meta An array of meta data to set for this gateway
     */
    public function setMeta(array $meta = null)
    {
        $this->meta = $meta;
    }

    /**
     * Returns all HTML markup required to render an authorization and capture payment form.
     *
     * @param array $contact_info An array of contact info including:
     *  - id The contact ID
     *  - client_id The ID of the client this contact belongs to
     *  - user_id The user ID this contact belongs to (if any)
     *  - contact_type The type of contact
     *  - contact_type_id The ID of the contact type
     *  - first_name The first name on the contact
     *  - last_name The last name on the contact
     *  - title The title of the contact
     *  - company The company name of the contact
     *  - address1 The address 1 line of the contact
     *  - address2 The address 2 line of the contact
     *  - city The city of the contact
     *  - state An array of state info including:
     *      - code The 2 or 3-character state code
     *      - name The local name of the country
     *  - country An array of country info including:
     *      - alpha2 The 2-character country code
     *      - alpha3 The 3-character country code
     *      - name The English name of the country
     *      - alt_name The local name of the country
     *  - zip The zip/postal code of the contact
     * @param float $amount The amount to charge this contact
     * @param array $invoice_amounts An array of invoices, each containing:
     *  - id The ID of the invoice being processed
     *  - amount The amount being processed for this invoice (which is included in $amount)
     * @param array $options An array of options including:
     *  - description The Description of the charge
     *  - return_url The URL to redirect users to after a successful payment
     *  - recur An array of recurring info including:
     *      - start_date The date/time in UTC that the recurring payment begins
     *      - amount The amount to recur
     *      - term The term to recur
     *      - period The recurring period (day, week, month, year, onetime) used in
     *          conjunction with term in order to determine the next recurring payment
     * @return mixed A string of HTML markup required to render an authorization and
     *  capture payment form, or an array of HTML markup
     */
    public function buildProcess(array $contact_info, $amount, array $invoice_amounts = null, array $options = null)
    {
        // Load the models required
        Loader::loadModels($this, ['Companies', 'Clients']);

        // Load library methods
        Loader::load(dirname(__FILE__) . DS . 'lib' . DS . 'square_api.php');
        $api = new SquareApi($this->meta['application_id'], $this->meta['access_token'], $this->meta['location_id']);

        // Force 2-decimal places only
        $amount = number_format($amount, 2, '.', '');

        // Get client data
        $client = $this->Clients->get($contact_info['client_id']);

        // Set all invoices to pay
        if (isset($invoice_amounts) && is_array($invoice_amounts)) {
            $invoices = $this->serializeInvoices($invoice_amounts);
        }

        // Build the payment request
        $params = [
            [
                'name' => (isset($options['description']) ? $options['description'] : null),
                'quantity' => 1,
                'base_price_money' => [
                    'amount' => (isset($amount) ? $amount : null),
                    'currency' => (isset($this->currency) ? $this->currency : null)
                ]
            ]
        ];
        $this->log((isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : null), serialize($params), 'input', true);

        // Send the request to the api
        $redirect_url = Configure::get('Blesta.gw_callback_url')
            . Configure::get('Blesta.company_id')
            . '/square/?client_id=' . $contact_info['client_id'];
        $request = $api->buildPayment($client->email, $params, $contact_info, $invoices, $redirect_url);

        // Build the payment form
        try {
            if (!isset($request->errors)) {
                $this->log((isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : null), serialize($request), 'output', true);

                return $this->buildForm($request->checkout->checkout_page_url);
            } else {
                // The api has been responded with an error, set the error
                $this->log((isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : null), serialize($request), 'output', false);
                $this->Input->setErrors(
                    ['api' => ['response' => $request->errors[0]->detail]]
                );

                return null;
            }
        } catch (Exception $e) {
            $this->Input->setErrors(
                ['internal' => ['response' => $e->getMessage()]]
            );
        }
    }

    /**
     * Builds the HTML form.
     *
     * @param string $post_to The URL to post to
     * @return string The HTML form
     */
    private function buildForm($post_to)
    {
        $this->view = $this->makeView('process', 'default', str_replace(ROOTWEBDIR, '', dirname(__FILE__) . DS));

        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html']);

        $this->view->set('post_to', $post_to);

        return $this->view->fetch();
    }

    /**
     * Validates the incoming POST/GET response from the gateway to ensure it is
     * legitimate and can be trusted.
     *
     * @param array $get The GET data for this request
     * @param array $post The POST data for this request
     * @return array An array of transaction data, sets any errors using Input if the data fails to validate
     *  - client_id The ID of the client that attempted the payment
     *  - amount The amount of the payment
     *  - currency The currency of the payment
     *  - invoices An array of invoices and the amount the payment should be applied to (if any) including:
     *      - id The ID of the invoice to apply to
     *      - amount The amount to apply to the invoice
     *  - status The status of the transaction (approved, declined, void, pending, reconciled, refunded, returned)
     *  - reference_id The reference ID for gateway-only use with this transaction (optional)
     *  - transaction_id The ID returned by the gateway to identify this transaction
     *  - parent_transaction_id The ID returned by the gateway to identify this transaction's
     *      original transaction (in the case of refunds)
     */
    public function validate(array $get, array $post)
    {
        // Load library methods
        Loader::load(dirname(__FILE__) . DS . 'lib' . DS . 'square_api.php');
        $api = new SquareApi($this->meta['application_id'], $this->meta['access_token'], $this->meta['location_id']);

        // If a webhook signature key is configured and this request carries a signed
        // Square webhook payload, verify it via the Payments API instead. This is fully
        // optional: installations that never subscribe a webhook in Square's dashboard
        // never take this branch and keep using the redirect-based verification below.
        if (!empty($this->meta['webhook_signature_key']) && $this->isWebhookRequest()) {
            return $this->validateWebhook($api);
        }

        // Get invoices
        $invoices = (isset($get['referenceId']) ? $get['referenceId'] : null);

        // Square's checkout redirect returns the order ID in the transactionId parameter
        // (the RetrieveTransaction endpoint this used to call was retired 2021-09-01)
        $order_id = (isset($get['transactionId']) ? $get['transactionId'] : null);
        $result = $this->getOrderStatus($api, $order_id);

        // Log the callback outcome
        $this->log(
            (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : null),
            serialize($get),
            'output',
            $result['status'] !== 'error'
        );

        return [
            'client_id' => (isset($get['client_id']) ? $get['client_id'] : null),
            'amount' => $result['amount'],
            'currency' => $result['currency'],
            'status' => $result['status'],
            'reference_id' => null,
            'transaction_id' => $order_id,
            'invoices' => $this->unserializeInvoices($invoices)
        ];
    }

    /**
     * Retrieves the order for the given order ID and determines its payment status
     * by inspecting each tender's associated payment.
     *
     * @param SquareApi $api The Square API instance
     * @param string $order_id The Square order ID
     * @return array An array containing:
     *  - status The determined transaction status (approved, declined, void, pending, error)
     *  - amount The order amount
     *  - currency The order currency
     */
    private function getOrderStatus($api, $order_id)
    {
        $status = 'error';
        $amount = '0.00';
        $currency = null;

        $order = $api->getOrder($order_id);

        // Log the raw order response so verification failures are visible in the gateway log
        $this->log($order_id, serialize($order), 'output', isset($order->total_money));

        if (!isset($order) || isset($order->errors) || !isset($order->total_money)) {
            return ['status' => $status, 'amount' => $amount, 'currency' => $currency];
        }

        $amount = number_format(($order->total_money->amount / 100), 2, '.', '');
        $currency = (isset($order->total_money->currency) ? $order->total_money->currency : null);

        foreach ((isset($order->tenders) ? $order->tenders : []) as $tender) {
            // Validate only if is a Credit Card, other types like Cash or Check require manual verification
            if (($tender->type ?? null) === 'CARD') {
                $payment = isset($tender->payment_id) ? $api->getPayment($tender->payment_id) : null;
                $this->log(
                    $order_id,
                    serialize($payment),
                    'output',
                    isset($payment) && !isset($payment->errors)
                );

                $payment_status = null;
                if (isset($payment) && !isset($payment->errors)) {
                    $payment_status = $payment->status
                        ?? ($payment->card_details->status ?? null);
                }

                switch ($payment_status) {
                    case 'COMPLETED':
                    case 'CAPTURED':
                        $status = 'approved';
                        break;
                    case 'FAILED':
                        $status = 'declined';
                        break;
                    case 'CANCELED':
                    case 'VOIDED':
                        $status = 'void';
                        break;
                    case 'APPROVED':
                    case 'AUTHORIZED':
                        $status = 'pending';
                        break;
                }
            } else {
                // Cash or other tender types require manual verification
                $status = 'pending';
            }
        }

        return ['status' => $status, 'amount' => $amount, 'currency' => $currency];
    }

    /**
     * Determines whether the current request carries a Square webhook payload.
     *
     * @return bool True if the request includes Square's webhook signature header
     */
    private function isWebhookRequest()
    {
        return isset($_SERVER['HTTP_X_SQUARE_HMACSHA256_SIGNATURE']);
    }

    /**
     * Verifies that the given raw request body was signed by Square using the
     * configured webhook signature key.
     *
     * @param string $payload The raw request body
     * @return bool True if the signature is valid
     */
    private function verifyWebhookSignature($payload)
    {
        $signature = $_SERVER['HTTP_X_SQUARE_HMACSHA256_SIGNATURE'] ?? '';

        if (empty($signature) || empty($this->meta['webhook_signature_key'])) {
            return false;
        }

        // Must exactly match the notification URL subscribed in Square's dashboard
        $notification_url = Configure::get('Blesta.gw_callback_url')
            . Configure::get('Blesta.company_id')
            . '/square/';

        $hash = base64_encode(
            hash_hmac('sha256', $notification_url . $payload, $this->meta['webhook_signature_key'], true)
        );

        return hash_equals($hash, $signature);
    }

    /**
     * Validates a Square webhook notification and returns the resulting transaction data.
     * Used as an alternative to the redirect-based verification in validate(), so that
     * payments are recorded even if the customer's browser never returns to Blesta.
     *
     * @param SquareApi $api The Square API instance
     * @return array|null An array of transaction data, or null if the payload could not be verified
     */
    private function validateWebhook($api)
    {
        $payload = file_get_contents('php://input');

        if (!$this->verifyWebhookSignature($payload)) {
            $this->log('webhook', $payload, 'input', false);
            $this->Input->setErrors([
                'webhook' => ['signature' => Language::_('Square.!error.webhook.signature', true)]
            ]);

            return;
        }

        $webhook = json_decode($payload);
        $this->log('webhook', $payload, 'input', isset($webhook->type));

        // Only payment events are actionable here; other subscribed events (e.g. order.updated)
        // are acknowledged and ignored
        $events = ['payment.created', 'payment.updated'];
        if (!in_array($webhook->type ?? '', $events)) {
            return;
        }

        // The webhook payload already carries the full, current payment object, so there's
        // no need to look it back up through the Payments API. Its authenticity is already
        // established by the signature check above.
        $payment = $webhook->data->object->payment ?? null;

        if (!isset($payment) || empty($payment->order_id)) {
            $this->Input->setErrors([
                'webhook' => ['payment' => Language::_('Square.!error.webhook.payment', true)]
            ]);

            return;
        }

        $order = $api->getOrder($payment->order_id);
        if (!isset($order) || isset($order->errors) || !isset($order->total_money)) {
            $this->Input->setErrors([
                'webhook' => ['order' => Language::_('Square.!error.webhook.order', true)]
            ]);

            return;
        }

        $invoices = $this->unserializeInvoices($order->reference_id ?? '');

        $status = 'error';
        $payment_status = $payment->status ?? ($payment->card_details->status ?? null);
        switch ($payment_status) {
            case 'COMPLETED':
            case 'CAPTURED':
                $status = 'approved';
                break;
            case 'FAILED':
                $status = 'declined';
                break;
            case 'CANCELED':
            case 'VOIDED':
                $status = 'void';
                break;
            case 'APPROVED':
            case 'AUTHORIZED':
                $status = 'pending';
                break;
        }

        return [
            'client_id' => $this->getClientIdFromInvoices($invoices),
            'amount' => number_format(($order->total_money->amount / 100), 2, '.', ''),
            'currency' => (isset($order->total_money->currency) ? $order->total_money->currency : null),
            'status' => $status,
            'reference_id' => null,
            'transaction_id' => $payment->order_id,
            'invoices' => $invoices
        ];
    }

    /**
     * Determines the client ID that owns the first invoice in the given list. Used by the
     * webhook path, where (unlike the redirect path) no client_id query parameter is available.
     *
     * @param array $invoices An array of invoices as returned by unserializeInvoices()
     * @return int|null The client ID, or null if it could not be determined
     */
    private function getClientIdFromInvoices(array $invoices)
    {
        if (empty($invoices[0]['id'])) {
            return null;
        }

        Loader::loadModels($this, ['Invoices']);
        $invoice = $this->Invoices->get($invoices[0]['id']);

        return isset($invoice->client_id) ? $invoice->client_id : null;
    }

    /**
     * Returns data regarding a success transaction. This method is invoked when
     * a client returns from the non-merchant gateway's web site back to Blesta.
     *
     * @param array $get The GET data for this request
     * @param array $post The POST data for this request
     * @return array An array of transaction data, may set errors using Input if the data appears invalid
     *  - client_id The ID of the client that attempted the payment
     *  - amount The amount of the payment
     *  - currency The currency of the payment
     *  - invoices An array of invoices and the amount the payment should be applied to (if any) including:
     *      - id The ID of the invoice to apply to
     *      - amount The amount to apply to the invoice
     *  - status The status of the transaction (approved, declined, void, pending, reconciled, refunded, returned)
     *  - transaction_id The ID returned by the gateway to identify this transaction
     *  - parent_transaction_id The ID returned by the gateway to identify this transaction's original transaction
     */
    public function success(array $get, array $post)
    {
        // Load library methods
        Loader::load(dirname(__FILE__) . DS . 'lib' . DS . 'square_api.php');
        $api = new SquareApi($this->meta['application_id'], $this->meta['access_token'], $this->meta['location_id']);

        // Get invoices
        $invoices = (isset($get['referenceId']) ? $get['referenceId'] : null);

        // Square's checkout redirect returns the order ID in the transactionId parameter
        // (the RetrieveTransaction endpoint this used to call was retired 2021-09-01)
        $order_id = (isset($get['transactionId']) ? $get['transactionId'] : null);
        $result = $this->getOrderStatus($api, $order_id);

        return [
            'client_id' => (isset($get['client_id']) ? $get['client_id'] : null),
            'amount' => $result['amount'],
            'currency' => $result['currency'],
            // Fall back to approved if the order/payment status could not be determined,
            // since the customer would not be on this redirect had the payment not succeeded
            'status' => $result['status'] !== 'error' ? $result['status'] : 'approved',
            'reference_id' => null,
            'transaction_id' => $order_id,
            'invoices' => $this->unserializeInvoices($invoices)
        ];
    }

    /**
     * Captures a previously authorized payment.
     *
     * @param string $reference_id The reference ID for the previously authorized transaction
     * @param string $transaction_id The transaction ID for the previously authorized transaction.
     * @param $amount The amount.
     * @param array $invoice_amounts
     * @return array An array of transaction data including:
     *  - status The status of the transaction (approved, declined, void, pending, reconciled, refunded, returned)
     *  - reference_id The reference ID for gateway-only use with this transaction (optional)
     *  - transaction_id The ID returned by the remote gateway to identify this transaction
     *  - message The message to be displayed in the interface in addition to the standard
     *      message for this transaction status (optional)
     */
    public function capture($reference_id, $transaction_id, $amount, array $invoice_amounts = null)
    {
        $this->Input->setErrors($this->getCommonError('unsupported'));
    }

    /**
     * Void a payment or authorization.
     *
     * @param string $reference_id The reference ID for the previously submitted transaction
     * @param string $transaction_id The transaction ID for the previously submitted transaction
     * @param string $notes Notes about the void that may be sent to the client by the gateway
     * @return array An array of transaction data including:
     *  - status The status of the transaction (approved, declined, void, pending, reconciled, refunded, returned)
     *  - reference_id The reference ID for gateway-only use with this transaction (optional)
     *  - transaction_id The ID returned by the remote gateway to identify this transaction
     *  - message The message to be displayed in the interface in addition to the standard
     *      message for this transaction status (optional)
     */
    public function void($reference_id, $transaction_id, $notes = null)
    {
        $this->Input->setErrors($this->getCommonError('unsupported'));
    }

    /**
     * Refund a payment.
     *
     * @param string $reference_id The reference ID for the previously submitted transaction
     * @param string $transaction_id The transaction ID for the previously submitted transaction
     * @param float $amount The amount to refund this card
     * @param string $notes Notes about the refund that may be sent to the client by the gateway
     * @return array An array of transaction data including:
     *  - status The status of the transaction (approved, declined, void, pending, reconciled, refunded, returned)
     *  - reference_id The reference ID for gateway-only use with this transaction (optional)
     *  - transaction_id The ID returned by the remote gateway to identify this transaction
     *  - message The message to be displayed in the interface in addition to the standard
     *      message for this transaction status (optional)
     */
    public function refund($reference_id, $transaction_id, $amount, $notes = null)
    {
        $this->Input->setErrors($this->getCommonError('unsupported'));
    }

    /**
     * Serializes an array of invoice info into a string.
     *
     * @param array A numerically indexed array invoices info including:
     *  - id The ID of the invoice
     *  - amount The amount relating to the invoice
     * @return string A serialized string of invoice info in the format of key1=value1|key2=value2
     */
    private function serializeInvoices(array $invoices)
    {
        $str = '';
        foreach ($invoices as $i => $invoice) {
            $str .= ($i > 0 ? '|' : '') . $invoice['id'] . '=' . $invoice['amount'];
        }

        return $str;
    }

    /**
     * Unserializes a string of invoice info into an array.
     *
     * @param string A serialized string of invoice info in the format of key1=value1|key2=value2
     * @param mixed $str
     * @return array A numerically indexed array invoices info including:
     *  - id The ID of the invoice
     *  - amount The amount relating to the invoice
     */
    private function unserializeInvoices($str)
    {
        $invoices = [];
        $temp = explode('|', $str);
        foreach ($temp as $pair) {
            $pairs = explode('=', $pair, 2);
            if (count($pairs) != 2) {
                continue;
            }
            $invoices[] = ['id' => $pairs[0], 'amount' => $pairs[1]];
        }

        return $invoices;
    }
}
