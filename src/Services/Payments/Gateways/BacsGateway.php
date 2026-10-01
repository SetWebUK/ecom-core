<?php

namespace Pine\Commerce\Services\Payments\Gateways;

use Pine\Commerce\Models\Order;
use Pine\Commerce\Services\Payments\Gateway;
use Pine\Commerce\Services\Payments\PaymentResult;
use Illuminate\Http\Request;

/**
 * Direct bank transfer (WooCommerce BACS): the order goes on hold until staff see the money arrive.
 * Settings: payments.bacs.enabled, .title, .description, .instructions and the account details
 * (payments.bacs.accounts as a list, or .account_name/.account_number/.sort_code/.bank_name/.iban/.bic).
 */
class BacsGateway extends Gateway
{
    public const DEFAULT_DESCRIPTION = 'Make your payment directly into our bank account. Please use your Order ID as the payment reference. Your order will not be shipped until the funds have cleared in our account.';

    public function code(): string
    {
        return 'bacs';
    }

    protected function defaultTitle(): string
    {
        return 'Direct bank transfer';
    }

    public function adminSettings(): array
    {
        return [
            'label' => 'Bank transfer',
            'icon' => 'building-library',
            'description' => 'Orders go “on hold” until you see the money arrive, then you mark them as processing.',
            'fields' => [
                'enabled' => ['type' => 'bool', 'label' => 'Accept bank transfers'],
                'title' => ['type' => 'text', 'label' => 'Name at checkout', 'default' => 'Direct bank transfer', 'wide' => true],
                'description' => ['type' => 'textarea', 'label' => 'Text under the name', 'default' => self::DEFAULT_DESCRIPTION],
                'instructions' => ['type' => 'textarea', 'label' => 'Instructions', 'help' => 'Shown on the order confirmation page and in the “on hold” email.'],
                'account_name' => ['type' => 'text', 'label' => 'Account name'],
                'bank_name' => ['type' => 'text', 'label' => 'Bank'],
                'sort_code' => ['type' => 'text', 'label' => 'Sort code', 'pattern' => '/^\d{2}-?\d{2}-?\d{2}$/', 'mono' => true, 'placeholder' => '12-34-56',
                    'message' => 'A sort code has 6 digits, e.g. 12-34-56.'],
                'account_number' => ['type' => 'text', 'label' => 'Account number', 'pattern' => '/^\d{6,10}$/', 'mono' => true,
                    'message' => 'An account number has 6 to 10 digits.'],
                'iban' => ['type' => 'text', 'label' => 'IBAN', 'optional' => true, 'mono' => true],
                'bic' => ['type' => 'text', 'label' => 'BIC / SWIFT', 'optional' => true, 'mono' => true],
            ],
            'webhook' => null,
        ];
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function description(): ?string
    {
        return parent::description() ?? self::DEFAULT_DESCRIPTION;
    }

    /** Text shown on the thank-you page and in the on-hold email. */
    public function instructions(): ?string
    {
        return trim((string) $this->setting('instructions')) ?: null;
    }

    /** @return array<int, array{account_name:?string, account_number:?string, sort_code:?string, bank_name:?string, iban:?string, bic:?string}> */
    public function accounts(): array
    {
        $fields = ['account_name', 'account_number', 'sort_code', 'bank_name', 'iban', 'bic'];
        $list = $this->setting('accounts');
        if (is_string($list)) {
            $list = json_decode($list, true);
        }
        if (! is_array($list) || ! $list) {
            $list = [array_combine($fields, array_map(fn ($f) => $this->setting($f), $fields))];
        }

        $accounts = [];
        foreach ($list as $account) {
            if (! is_array($account)) {
                continue;
            }
            $row = [];
            foreach ($fields as $field) {
                $value = trim((string) ($account[$field] ?? ''));
                $row[$field] = $value !== '' ? $value : null;
            }
            if ($row['account_number'] || $row['iban']) {
                if ($row['sort_code'] && preg_match('/^\d{6}$/', $row['sort_code'])) {
                    $row['sort_code'] = implode('-', str_split($row['sort_code'], 2));
                }
                $accounts[] = $row;
            }
        }

        return $accounts;
    }

    public function process(Order $order, Request $request): PaymentResult
    {
        // one pending transfer per order, also when the customer retries from the order-pay page
        $order->payments()->updateOrCreate(
            ['gateway' => $this->code(), 'reference' => (string) $order->number, 'status' => 'pending'],
            ['amount' => $order->total],
        );
        $order->updateStatus('on-hold', 'Awaiting BACS payment.');

        return PaymentResult::success($order->view_url);
    }
}
