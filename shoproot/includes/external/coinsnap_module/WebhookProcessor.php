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
    public function __construct(
        private Store $store,
        private Orders $orders,
        callable $fetchInvoice,
        callable $log,
        private array $statuses
    ) {
        $this->fetchInvoice = $fetchInvoice;
        $this->log = $log;
    }

    public function handle(string $rawBody, string $signature, int $now): int
    {
        $problem = Signature::coinsnapProblem($signature, $rawBody, (string) ($this->store->webhook()['secret'] ?? ''), $now);
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
        if ($expected === null && $this->store->orderHasInvoices($orderId)) {
            $this->logSecurity('invoice not created for this order', $invoiceId);
            return 200;
        }
        // Orders placed before 1.0.1 have no record; check against the order total.
        $expected ??= ['amount' => number_format(round((float) $order['total'], 2), 2, '.', ''), 'currency' => (string) $order['currency']];

        $decision = InvoiceDecision::decide($invoice, false, (string) $expected['amount'], (string) $expected['currency']);
        if ($decision !== InvoiceDecision::PAID && ($claimsPaid || $decision === InvoiceDecision::MISMATCH)) {
            $this->logSecurity($claimsPaid ? 'forged status' : 'amount mismatch', $invoiceId);
            return 200;
        }

        $this->apply($decision, $orderId, (int) $order['orders_status'], $invoiceId, $expected);
        return 200;
    }

    // Moves an order forward only: paid orders, and orders past the awaiting statuses, never change.
    private function apply(string $decision, int $orderId, int $current, string $invoiceId, array $expected): void
    {
        $default = (int) $this->statuses['default'];
        $processing = (int) $this->statuses['processing'];
        $expired = (int) $this->statuses['expired'];
        $awaiting = in_array($current, [$default, $processing], true) && !$this->store->orderIsPaid($orderId);

        if ($decision === InvoiceDecision::PAID) {
            // A late payment can still settle an order that expired.
            if (!$awaiting && $current !== $expired) {
                $this->logInfo('no change from status ' . $current, $invoiceId);
                return;
            }
            if (!$this->store->claimPaid($invoiceId, $orderId, (string) $expected['amount'], (string) $expected['currency'])) {
                $this->logInfo('already processed', $invoiceId);
                return;
            }
            $this->orders->setStatus($orderId, (int) $this->statuses['settled'], 'Coinsnap invoice ' . $invoiceId . ' settled.');
            $this->logInfo('paid', $invoiceId);
            return;
        }
        if ($decision === InvoiceDecision::PROCESSING && $awaiting && $current === $default && $default !== $processing) {
            $this->orders->setStatus($orderId, $processing, 'Coinsnap invoice ' . $invoiceId . ': payment received, waiting for confirmation.');
            $this->logInfo('status processing', $invoiceId);
            return;
        }
        if ($decision === InvoiceDecision::EXPIRED && $awaiting) {
            $this->orders->setStatus($orderId, $expired, 'Coinsnap invoice ' . $invoiceId . ' expired.');
            $this->logInfo('status expired', $invoiceId);
            return;
        }
        $this->logInfo('no change', $invoiceId);
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
