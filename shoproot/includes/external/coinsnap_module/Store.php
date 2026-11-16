<?php

declare(strict_types=1);

namespace CoinsnapModified;

// The webhook this module registered (with its secret) and the invoices it created; tables made on first use.
class Store
{
    public function __construct()
    {
        xtc_db_query("CREATE TABLE IF NOT EXISTS `coinsnap_webhook` (
            `id` TINYINT UNSIGNED NOT NULL,
            `webhook_id` VARCHAR(64) NOT NULL,
            `secret` VARCHAR(255) NOT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        xtc_db_query("CREATE TABLE IF NOT EXISTS `coinsnap_invoice` (
            `invoice_id` VARCHAR(64) NOT NULL,
            `orders_id` INT UNSIGNED NOT NULL,
            `amount` VARCHAR(32) NOT NULL,
            `currency` VARCHAR(8) NOT NULL,
            `paid_at` DATETIME NULL,
            `claim` CHAR(32) NULL,
            PRIMARY KEY (`invoice_id`),
            KEY `orders_id` (`orders_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function webhook(): ?array
    {
        $row = xtc_db_fetch_array(xtc_db_query("SELECT `webhook_id`, `secret` FROM `coinsnap_webhook` WHERE `id` = 1"));
        return is_array($row) && $row !== [] ? $row : null;
    }

    public function saveWebhook(string $webhookId, string $secret): void
    {
        xtc_db_query("REPLACE INTO `coinsnap_webhook` (`id`, `webhook_id`, `secret`) VALUES (1, '" . xtc_db_input($webhookId) . "', '" . xtc_db_input($secret) . "')");
    }

    public function rememberInvoice(string $invoiceId, int $orderId, string $amount, string $currency): void
    {
        xtc_db_query("INSERT IGNORE INTO `coinsnap_invoice` (`invoice_id`, `orders_id`, `amount`, `currency`) VALUES ('"
            . xtc_db_input($invoiceId) . "', " . (int) $orderId . ", '" . xtc_db_input($amount) . "', '" . xtc_db_input($currency) . "')");
    }

    // The recorded invoice, or null when it was not created for this order.
    public function findInvoice(string $invoiceId, int $orderId): ?array
    {
        $row = xtc_db_fetch_array(xtc_db_query("SELECT `amount`, `currency` FROM `coinsnap_invoice` WHERE `invoice_id` = '"
            . xtc_db_input($invoiceId) . "' AND `orders_id` = " . (int) $orderId));
        return is_array($row) && $row !== [] ? $row : null;
    }

    // Orders placed before 1.0.1 have no rows.
    public function orderHasInvoices(int $orderId): bool
    {
        $row = xtc_db_fetch_array(xtc_db_query("SELECT 1 AS `found` FROM `coinsnap_invoice` WHERE `orders_id` = " . (int) $orderId . " LIMIT 1"));
        return is_array($row) && $row !== [];
    }

    // Paid orders are never moved back, even when the paid and processing statuses are the same.
    public function orderIsPaid(int $orderId): bool
    {
        $row = xtc_db_fetch_array(xtc_db_query("SELECT 1 AS `found` FROM `coinsnap_invoice` WHERE `orders_id` = " . (int) $orderId . " AND `paid_at` IS NOT NULL LIMIT 1"));
        return is_array($row) && $row !== [];
    }

    // True for the first caller only, so two webhooks for one invoice can't both mark the order paid.
    public function claimPaid(string $invoiceId, int $orderId, string $amount, string $currency): bool
    {
        $id = xtc_db_input($invoiceId);
        xtc_db_query("INSERT IGNORE INTO `coinsnap_invoice` (`invoice_id`, `orders_id`, `amount`, `currency`) VALUES ('"
            . $id . "', " . (int) $orderId . ", '" . xtc_db_input($amount) . "', '" . xtc_db_input($currency) . "')");

        // The update only lands while unclaimed; reading the token back shows whether this caller won.
        $token = bin2hex(random_bytes(16));
        xtc_db_query("UPDATE `coinsnap_invoice` SET `paid_at` = NOW(), `claim` = '" . $token . "' WHERE `invoice_id` = '"
            . $id . "' AND `orders_id` = " . (int) $orderId . " AND `paid_at` IS NULL");
        $row = xtc_db_fetch_array(xtc_db_query("SELECT `claim` FROM `coinsnap_invoice` WHERE `invoice_id` = '" . $id . "'"));
        return is_array($row) && ($row['claim'] ?? '') === $token;
    }
}
