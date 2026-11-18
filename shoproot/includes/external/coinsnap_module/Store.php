<?php

declare(strict_types=1);

namespace CoinsnapModified;

// The webhooks this module registered (one secret per Coinsnap store), the invoices it created,
// and the order status it last set; tables made on first use.
class Store
{
    // Orders placed before invoices were recorded are accepted only this long after the records started (guide 6.7).
    public const LEGACY_WINDOW_SECONDS = 86400;

    public function __construct()
    {
        xtc_db_query("CREATE TABLE IF NOT EXISTS `coinsnap_webhook_store` (
            `store_id` VARCHAR(64) NOT NULL,
            `webhook_id` VARCHAR(64) NOT NULL,
            `secret` VARCHAR(255) NOT NULL,
            PRIMARY KEY (`store_id`)
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
        xtc_db_query("CREATE TABLE IF NOT EXISTS `coinsnap_order_status` (
            `orders_id` INT UNSIGNED NOT NULL,
            `orders_status_id` INT NOT NULL,
            PRIMARY KEY (`orders_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        xtc_db_query("CREATE TABLE IF NOT EXISTS `coinsnap_meta` (
            `name` VARCHAR(64) NOT NULL,
            `value` VARCHAR(255) NOT NULL,
            PRIMARY KEY (`name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // The first run of this version; INSERT IGNORE keeps the first value.
        xtc_db_query("INSERT IGNORE INTO `coinsnap_meta` (`name`, `value`) VALUES ('records_since', '" . time() . "')");
    }

    // The webhook registered for this Coinsnap store, or null.
    public function webhook(string $storeId): ?array
    {
        if ($storeId === '') {
            return null;
        }
        $row = xtc_db_fetch_array(xtc_db_query("SELECT `webhook_id`, `secret` FROM `coinsnap_webhook_store` WHERE `store_id` = '" . xtc_db_input($storeId) . "'"));
        return is_array($row) && $row !== [] ? $row : null;
    }

    // Pre-release 1.0.1 kept one webhook without its store in `coinsnap_webhook`. Used only until the next checkout moves it.
    public function legacyWebhook(): ?array
    {
        $exists = xtc_db_fetch_array(xtc_db_query("SHOW TABLES LIKE 'coinsnap_webhook'"));
        if (!is_array($exists) || $exists === []) {
            return null;
        }
        $row = xtc_db_fetch_array(xtc_db_query("SELECT `webhook_id`, `secret` FROM `coinsnap_webhook` WHERE `id` = 1"));
        return is_array($row) && $row !== [] ? $row : null;
    }

    public function saveWebhook(string $storeId, string $webhookId, string $secret): void
    {
        xtc_db_query("REPLACE INTO `coinsnap_webhook_store` (`store_id`, `webhook_id`, `secret`) VALUES ('" . xtc_db_input($storeId) . "', '"
            . xtc_db_input($webhookId) . "', '" . xtc_db_input($secret) . "')");
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

    // Every invoice this module created for the order, with the amount and currency it asked for.
    public function invoicesForOrder(int $orderId): array
    {
        $rows = [];
        $query = xtc_db_query("SELECT `invoice_id`, `amount`, `currency` FROM `coinsnap_invoice` WHERE `orders_id` = " . (int) $orderId);
        while ($row = xtc_db_fetch_array($query)) {
            $rows[] = $row;
        }
        return $rows;
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

    // The status this module last gave the order. Anything else on the order was set by the merchant (guide 6.8).
    public function moduleStatus(int $orderId): ?int
    {
        $row = xtc_db_fetch_array(xtc_db_query("SELECT `orders_status_id` FROM `coinsnap_order_status` WHERE `orders_id` = " . (int) $orderId));
        return is_array($row) && isset($row['orders_status_id']) ? (int) $row['orders_status_id'] : null;
    }

    public function rememberStatus(int $orderId, int $statusId): void
    {
        xtc_db_query("REPLACE INTO `coinsnap_order_status` (`orders_id`, `orders_status_id`) VALUES (" . (int) $orderId . ", " . (int) $statusId . ")");
    }

    // Unix time this version first ran; orders placed before it may have no invoice record.
    public function recordsSince(): int
    {
        $row = xtc_db_fetch_array(xtc_db_query("SELECT `value` FROM `coinsnap_meta` WHERE `name` = 'records_since'"));
        return (int) ($row['value'] ?? 0);
    }
}
