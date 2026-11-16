<?php

declare(strict_types=1);

namespace CoinsnapModified;

// The few order facts the webhook needs, read from Modified's own tables.
class Orders
{
    // Payment method, current status and the total in the order's currency, or null when there is no such order.
    public function find(int $orderId): ?array
    {
        $order = xtc_db_fetch_array(xtc_db_query("SELECT `orders_id`, `payment_method`, `orders_status`, `currency`, `currency_value` FROM "
            . TABLE_ORDERS . " WHERE `orders_id` = " . (int) $orderId));
        if (!is_array($order) || $order === []) {
            return null;
        }
        $total = xtc_db_fetch_array(xtc_db_query("SELECT `value` FROM " . TABLE_ORDERS_TOTAL . " WHERE `orders_id` = " . (int) $orderId
            . " AND `class` = 'ot_total' LIMIT 1"));
        $order['total'] = (float) ($total['value'] ?? 0) * (float) ($order['currency_value'] ?: 1);
        return $order;
    }

    public function setStatus(int $orderId, int $statusId, string $comment): void
    {
        xtc_db_query("UPDATE " . TABLE_ORDERS . " SET `orders_status` = " . (int) $statusId . " WHERE `orders_id` = " . (int) $orderId);
        xtc_db_perform(TABLE_ORDERS_STATUS_HISTORY, [
            'orders_id' => (int) $orderId,
            'orders_status_id' => (int) $statusId,
            'date_added' => 'now()',
            'customer_notified' => '0',
            'comments' => $comment,
            'comments_sent' => '0',
        ]);
    }
}
