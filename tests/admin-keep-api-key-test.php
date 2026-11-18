<?php
// php tests/admin-keep-api-key-test.php
// admin/modules.php saves every posted configuration value without loading the payment module. The admin hook must drop
// an empty API key field so the stored key is kept, and leave every other request alone.
declare(strict_types=1);

$hook = __DIR__ . '/../shoproot/admin/includes/extra/application_top/application_top_end/coinsnap_keep_api_key.php';
$pass = 0;
$fail = 0;

function run_hook(string $hook, string $page, array $get, array $post): array
{
    if (!is_file($hook)) {
        return $post;
    }
    $_GET = $get;
    $_POST = $post;
    $current_page = $page;
    require $hook;
    return $_POST;
}

function check(bool $ok, string $name): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . "\n";
}

$save = ['set' => 'payment', 'module' => 'coinsnap', 'action' => 'save'];
$form = ['configuration' => ['MODULE_PAYMENT_COINSNAP_API_KEY' => '', 'MODULE_PAYMENT_COINSNAP_STORE_ID' => 'S1', 'MODULE_PAYMENT_COINSNAP_SORT_ORDER' => '1']];

$out = run_hook($hook, 'modules.php', $save, $form);
check(!array_key_exists('MODULE_PAYMENT_COINSNAP_API_KEY', $out['configuration']), 'empty key field is not saved (stored key kept)');
check(($out['configuration']['MODULE_PAYMENT_COINSNAP_STORE_ID'] ?? null) === 'S1', 'other settings are still saved');

$out = run_hook($hook, 'modules.php', $save, ['configuration' => ['MODULE_PAYMENT_COINSNAP_API_KEY' => '   ']]);
check(!array_key_exists('MODULE_PAYMENT_COINSNAP_API_KEY', $out['configuration']), 'whitespace-only key field is not saved');

$out = run_hook($hook, 'modules.php', $save, ['configuration' => ['MODULE_PAYMENT_COINSNAP_API_KEY' => 'new-key']]);
check(($out['configuration']['MODULE_PAYMENT_COINSNAP_API_KEY'] ?? null) === 'new-key', 'a new key is saved');

$out = run_hook($hook, 'modules.php', ['module' => 'paypal'] + $save, $form);
check(array_key_exists('MODULE_PAYMENT_COINSNAP_API_KEY', $out['configuration']), 'other modules untouched');

$out = run_hook($hook, 'configuration.php', $save, $form);
check(array_key_exists('MODULE_PAYMENT_COINSNAP_API_KEY', $out['configuration']), 'other admin pages untouched');

$out = run_hook($hook, 'modules.php', $save, ['configuration' => 'x']);
check($out['configuration'] === 'x', 'non-array configuration ignored');

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
