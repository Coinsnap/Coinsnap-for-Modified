<?php

declare(strict_types=1);

namespace CoinsnapModified;

// Decides whether a customer coming back from the payment page may finish the checkout (guide 6.6).
// Only the API's answer for the invoices this module recorded counts; nothing from the request is used.
class ReturnCheck
{
    // Paid, or the payment is seen and waiting for confirmation: the checkout completes, the webhook does the rest.
    public const SEEN = 'seen';
    public const NOT_PAID = 'not paid';
    // The API could not be asked; the order is neither placed nor refused.
    public const UNKNOWN = 'unknown';

    /** @param callable(string): array $fetchInvoice fetches the invoice from the API, throws on failure */
    public static function check(Store $store, int $orderId, callable $fetchInvoice): string
    {
        if ($orderId <= 0) {
            return self::NOT_PAID;
        }
        if ($store->orderIsPaid($orderId)) {
            return self::SEEN;
        }

        $apiError = false;
        foreach ($store->invoicesForOrder($orderId) as $record) {
            try {
                $invoice = $fetchInvoice((string) $record['invoice_id']);
            } catch (\Coinsnap\Exception\RequestException $e) {
                if (!in_array($e->getCode(), [400, 404], true)) {
                    $apiError = true;
                }
                continue;
            } catch (\Throwable $e) {
                $apiError = true;
                continue;
            }
            if ((string) ($invoice['orderId'] ?? '') !== (string) $orderId) {
                continue;
            }
            $decision = InvoiceDecision::decide($invoice, false, (string) $record['amount'], (string) $record['currency']);
            if ($decision === InvoiceDecision::PAID || $decision === InvoiceDecision::PROCESSING) {
                return self::SEEN;
            }
        }

        return $apiError ? self::UNKNOWN : self::NOT_PAID;
    }
}
