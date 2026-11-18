<?php

declare(strict_types=1);

namespace CoinsnapModified;

// Handles one webhook. Only the invoice id is taken from the request; every decision comes from the API.
class WebhookProcessor
{
    private const SECURITY_LINES_PER_MINUTE = 10;

    /** @var callable(string): array  fetches the invoice from the API, throws on failure */
    private $fetchInvoice;
    /** @var callable(string): void */
    private $log;

    // $statuses: default (new order), processing, settled and expired order status ids.
    // $storeId: the configured Coinsnap store; its webhook secret is the only one accepted (guide 6.4).
    public function __construct(
        private Store $store,
        private Orders $orders,
        callable $fetchInvoice,
        callable $log,
        private array $statuses,
        private string $storeId = ''
    ) {
        $this->fetchInvoice = $fetchInvoice;
        $this->log = $log;
    }

    public function handle(string $rawBody, string $signature, int $now): int
    {
        $webhook = $this->store->webhook($this->storeId) ?? $this->store->legacyWebhook();
        $problem = Signature::coinsnapProblem($signature, $rawBody, (string) ($webhook['secret'] ?? ''), $now);
        if ($problem !== null) {
            $this->logSecurity($problem);
            return 401;
        }

        $body = json_decode($rawBody, true);
        $invoiceId = (is_array($body) && is_string($body['invoiceId'] ?? null)) ? $body['invoiceId'] : '';
        if ($invoiceId === '') {
            return 200;
        }
        $claimsPaid = ($body['type'] ?? '') === 'Settled';

        try {
            $invoice = ($this->fetchInvoice)($invoiceId);
        } catch (\Coinsnap\Exception\RequestException $e) {
            if (in_array($e->getCode(), [400, 404], true)) {
                // The dashboard's "Send test webhook" button lands here.
                $this->logInfo('unknown invoice', $invoiceId);
                return 200;
            }
            $this->logInfo('retry: API unavailable', $invoiceId);
            return 503;
        } catch (\Throwable $e) {
            $this->logInfo('retry: API unavailable', $invoiceId);
            return 503;
        }

        $orderId = (int) ($invoice['orderId'] ?? 0);
        $order = $orderId > 0 ? $this->orders->find($orderId) : null;
        if ($order === null || ($order['payment_method'] ?? '') !== 'coinsnap') {
            $this->logInfo('not ours', $invoiceId);
            return 200;
        }

        $expected = $this->store->findInvoice($invoiceId, $orderId);
        if ($expected === null) {
            if ($this->store->orderHasInvoices($orderId) || !$this->isLegacyOrder($order, $now)) {
                $this->logSecurity('invoice not created for this order', $invoiceId);
                return 200;
            }
            // Orders placed before invoices were recorded: checked against the order total, for one day after the upgrade only (guide 6.7).
            $expected = ['amount' => number_format(round((float) $order['total'], 2), 2, '.', ''), 'currency' => (string) $order['currency']];
        }

        $decision = InvoiceDecision::decide($invoice, false, (string) $expected['amount'], (string) $expected['currency']);
        if ($decision !== InvoiceDecision::PAID && ($claimsPaid || $decision === InvoiceDecision::MISMATCH)) {
            $this->logSecurity($claimsPaid ? 'forged status' : 'amount mismatch', $invoiceId);
            return 200;
        }

        $this->apply($decision, $orderId, (int) $order['orders_status'], $invoiceId, $expected);
        return 200;
    }

    // Moves an order forward only. A paid order never changes; a status the merchant set by hand is kept,
    // except that a real (late) payment may still settle an expired order (guide 6.8).
    private function apply(string $decision, int $orderId, int $current, string $invoiceId, array $expected): void
    {
        $processing = (int) $this->statuses['processing'];
        $expired = (int) $this->statuses['expired'];
        $settled = (int) $this->statuses['settled'];
        $paid = $this->store->orderIsPaid($orderId);
        $untouched = $this->setByModule($orderId, $current);

        if ($decision === InvoiceDecision::PAID) {
            if ($paid || (!$untouched && $current !== $expired)) {
                $this->logInfo($paid ? 'already paid' : 'no change from status ' . $current, $invoiceId);
                return;
            }
            if (!$this->store->claimPaid($invoiceId, $orderId, (string) $expected['amount'], (string) $expected['currency'])) {
                $this->logInfo('already processed', $invoiceId);
                return;
            }
            $this->setStatus($orderId, $settled, 'Coinsnap invoice ' . $invoiceId . ' settled.');
            $this->logInfo('paid', $invoiceId);
            return;
        }
        if ($paid || !$untouched) {
            $this->logInfo('no change', $invoiceId);
            return;
        }
        // When "processing" is the same status as "settled" (the install default), an unconfirmed payment would look paid.
        if ($decision === InvoiceDecision::PROCESSING && $processing !== $settled && $current !== $processing && $current !== $expired) {
            $this->setStatus($orderId, $processing, 'Coinsnap invoice ' . $invoiceId . ': payment received, waiting for confirmation.');
            $this->logInfo('status processing', $invoiceId);
            return;
        }
        if ($decision === InvoiceDecision::EXPIRED && $current !== $expired) {
            $this->setStatus($orderId, $expired, 'Coinsnap invoice ' . $invoiceId . ' expired.');
            $this->logInfo('status expired', $invoiceId);
            return;
        }
        $this->logInfo('no change', $invoiceId);
    }

    // True while the order still has the status this module gave it. Orders from before that record existed
    // count as untouched only in the new or processing status.
    private function setByModule(int $orderId, int $current): bool
    {
        $recorded = $this->store->moduleStatus($orderId);
        if ($recorded !== null) {
            return $recorded === $current;
        }
        return in_array($current, [(int) $this->statuses['default'], (int) $this->statuses['processing']], true);
    }

    private function setStatus(int $orderId, int $statusId, string $comment): void
    {
        $this->orders->setStatus($orderId, $statusId, $comment);
        $this->store->rememberStatus($orderId, $statusId);
    }

    // No invoice record: accepted only for an order placed before records began, and only within the window.
    private function isLegacyOrder(array $order, int $now): bool
    {
        $since = $this->store->recordsSince();
        $placed = (int) ($order['purchased_at'] ?? 0);
        return $since > 0 && $placed > 0 && $placed < $since && $now < $since + Store::LEGACY_WINDOW_SECONDS;
    }

    private function logInfo(string $reason, string $invoiceId = ''): void
    {
        ($this->log)($this->line('INFO', $reason, $invoiceId));
    }

    // At most 10 security lines a minute, so a flood of fake requests can't fill the log.
    private function logSecurity(string $reason, string $invoiceId = ''): void
    {
        $file = sys_get_temp_dir() . '/coinsnap_modified_seclog_' . gmdate('YmdHi');
        $count = (int) @file_get_contents($file) + 1;
        @file_put_contents($file, (string) $count);
        if ($count === self::SECURITY_LINES_PER_MINUTE + 1) {
            ($this->log)('Coinsnap SECURITY webhook: more rejections this minute, not logged');
        }
        if ($count <= self::SECURITY_LINES_PER_MINUTE) {
            ($this->log)($this->line('SECURITY', $reason, $invoiceId));
        }
    }

    private function line(string $level, string $reason, string $invoiceId): string
    {
        // The invoice id comes from the request.
        $id = substr((string) preg_replace('/[^A-Za-z0-9_-]/', '', $invoiceId), 0, 64);
        return sprintf('Coinsnap %s webhook reason=%s invoice=%s', $level, $reason, $id !== '' ? $id : '-');
    }
}
