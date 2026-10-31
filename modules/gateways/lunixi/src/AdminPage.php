<?php

declare(strict_types=1);

namespace Lunixi\WHMCS;

/** Invoked by WHMCS's addon route, whose role access list is configured natively. */
final class AdminPage
{
    /** @param array<string,mixed> $vars */
    public function render(array $vars): void
    {
        $adminId = Access::admin('Manage Invoice');
        $integration = Runtime::boot();
        $e = [Runtime::class, 'escape'];
        $t = static fn (string $key): string => $e((string) ($vars['_lang'][$key] ?? Runtime::text($key)));
        $message = '';
        $requestedId = $_POST['invoiceid'] ?? $_GET['invoiceid'] ?? null;
        $id = $requestedId !== null && $requestedId !== '' ? Access::id($requestedId) : null;
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            Access::csrf(true);
            $action = (string) ($_POST['lunixi_action'] ?? '');
            if ($action === 'profile') {
                Access::admin('Configure Payment Gateways');
                $profile = [];
                foreach (['merchantId', 'keyId', 'privateKey', 'webhookSecret', 'mode', 'environment'] as $key) {
                    $profile[$key] = is_string($_POST[$key] ?? null) ? $_POST[$key] : '';
                }
                $profile['preauth'] = isset($_POST['preauth']);
                $integration->profiles->create(is_string($_POST['label'] ?? null) ? $_POST['label'] : '', $profile);
                $message = $t('created');
            } elseif ($action === 'acknowledge') {
                $integration->outbox->acknowledge((string) ($_POST['job_id'] ?? ''));
                $message = $t('acknowledged');
            } elseif ($action === 'prepare' && $id !== null) {
                $op = $integration->prepare($id, $adminId, (string) ($_POST['operation'] ?? ''), is_string($_POST['amount'] ?? null) ? $_POST['amount'] : null);
                $message = $t('prepared') . ' ' . $e($op);
            } elseif ($action === 'execute') {
                $state = $integration->execute((string) ($_POST['operation_id'] ?? ''));
                $message = $t($state === 'confirmed' ? 'confirmed' : 'pending');
            } elseif ($action === 'retry_failed' && $id !== null) {
                $integration->retryFailed($id);
                $message = $t('prepared');
            } elseif ($action === 'reconcile' && $id !== null) {
                $integration->reconcile($id);
                $message = $t('state');
            } else {
                throw new \InvalidArgumentException('Unknown admin action.');
            }
        }
        $url = (string) ($vars['modulelink'] ?? 'addonmodules.php?module=lunixi_payments');
        // The modulelink is native-generated, not a request parameter.
        $form = static fn (string $action): string => '<form method="post" action="' . $e($url) . '"><input type="hidden" name="token" value="' . $e(Access::token()) . '"><input type="hidden" name="lunixi_action" value="' . $e($action) . '">';
        echo '<div class="lunixi-admin"><img src="../modules/gateways/lunixi/assets/lunixi.svg" width="160" alt="Lunixi"><h2>' . $t('title') . '</h2><p>' . $t('help') . '</p><p role="status">' . $message . '</p>';
        echo '<p>' . $t('rotation') . '</p><h3>' . $t('profiles') . '</h3><ul>';
        foreach ($integration->profiles->listing() as $profile) {
            echo '<li>' . (int) $profile->id . ' — ' . $e($profile->label) . '<br>' . $t('webhook') . ': <code>' . $e(Runtime::baseUrl() . '/modules/gateways/callback/lunixi.php?profile=' . (int) $profile->id) . '</code></li>';
        }
        echo '</ul>';
        if ((new \WHMCS\Authentication\CurrentUser())->admin()?->hasPermission('Configure Payment Gateways')) {
            echo '<details><summary>' . $t('profile') . '</summary>' . $form('profile');
            foreach (['label', 'merchantId', 'keyId', 'privateKey', 'webhookSecret'] as $key) {
                $type = in_array($key, ['privateKey', 'webhookSecret'], true) ? 'password' : 'text';
                echo '<label>' . $t($key) . ' <input class="form-control" type="' . $type . '" name="' . $e($key) . '" autocomplete="new-password" required></label>';
            }
            echo '<label>' . $t('environment') . ' <select name="environment"><option value="TEST">TEST</option><option value="LIVE">LIVE</option></select></label>';
            echo '<label>' . $t('mode') . ' <select name="mode"><option value="embedded">' . $t('embedded') . '</option><option value="hosted">' . $t('hosted') . '</option></select></label><label><input type="checkbox" name="preauth" value="1"> ' . $t('preauth') . '</label><button class="btn btn-primary" type="submit">' . $t('save') . '</button></form></details>';
        }
        echo '<h3>' . $t('operations') . '</h3><form method="get" action="addonmodules.php"><input type="hidden" name="module" value="lunixi_payments"><label>' . $t('invoice_id') . ' <input type="number" min="1" name="invoiceid" value="' . ($id ?? '') . '" required></label><button type="submit">' . $t('inspect') . '</button></form>';
        if ($id !== null) {
            $snapshot = $integration->invoices->snapshot($id);
            echo '<p>' . $t('state') . ': ' . $e($snapshot['status']) . ' / ' . $e($snapshot['currency']) . '</p>';
            foreach ($integration->operations($id) as $operation) {
                echo $form('execute') . '<input type="hidden" name="invoiceid" value="' . $id . '"><input type="hidden" name="operation_id" value="' . $e($operation->id) . '"><p><code>' . $e($operation->id) . '</code> ' . $e($operation->action) . ' / ' . $e($operation->state) . ' / ' . $e($operation->amount === null ? '' : \Lunixi\WHMCS\Vendor\Lunixi\Sdk\Commerce\Money::decimal((int) $operation->amount, \Lunixi\WHMCS\Vendor\Lunixi\Sdk\Commerce\Money::exponent($snapshot['currency']))) . ' ' . $e($snapshot['currency']) . '</p><button type="submit">' . $t('execute') . '</button></form>';
            }
            echo $form('prepare') . '<input type="hidden" name="invoiceid" value="' . $id . '"><label>' . $t('action') . ' <select name="operation">';
            foreach (['capture', 'refund', 'void'] as $action) {
                echo '<option value="' . $action . '">' . $t($action) . '</option>';
            }
            echo '</select></label><label>' . $t('amount') . ' <input type="text" inputmode="decimal" name="amount" pattern="[0-9]+(\.[0-9]+)?"></label><button type="submit">' . $t('prepare') . '</button></form>';
            echo $form('retry_failed') . '<input type="hidden" name="invoiceid" value="' . $id . '"><button type="submit">' . $t('retry_failed') . '</button></form>';
            echo $form('reconcile') . '<input type="hidden" name="invoiceid" value="' . $id . '"><button type="submit">' . $t('reconcile') . '</button></form>';
        }
        echo '<h3>' . $t('outbox') . '</h3><p>' . $t('review') . '</p>';
        foreach ($integration->outbox->attention() as $job) {
            echo $form('acknowledge') . '<input type="hidden" name="job_id" value="' . $e($job['id']) . '"><p><code>' . $e($job['id']) . '</code> ' . $e($job['kind']) . ' / ' . $e($job['state']) . ' / ' . (int) $job['reference'] . '</p><button type="submit">' . $t('acknowledge') . '</button></form>';
        }
        echo '</div>';
    }
}
