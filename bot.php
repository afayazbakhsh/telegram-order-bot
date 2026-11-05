<?php
declare(strict_types=1);

require __DIR__ . '/orders.php';
require __DIR__ . '/state_store.php';

final class TelegramError extends RuntimeException {}

final class Bot
{
    private const REFERENCE = '$3.49 USD reference';
    private const TERMS = 'Orders are delivered manually to public Telegram channels. Member counts may drop over time. For help with delivery, cancellation or refunds, contact Crowdio Support.';
    private const HOME = ['Main Menu', 'home'];
    private const NEW = ['🛒 New Order', 'new'];
    private const MINE = ['📦 My Orders', 'mine'];

    private float $acceptUntil = INF;

    public function __construct(
        private string $token,
        private int $admin,
        private array $prices,
        private string $support,
        private Orders $orders,
        private ?GitHubState $state,
        private string $dbPath,
    ) {}

    private function api(string $method, array $args = [], int $timeout = 8): mixed
    {
        $curl = curl_init('https://api.telegram.org/bot' . $this->token . '/' . $method);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($args ?: new stdClass(), JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
        ]);
        $response = curl_exec($curl);
        $body = is_string($response) ? json_decode($response, true) : null;
        if (empty($body['ok'])) {
            $alreadyRefunded = $method === 'refundStarPayment' &&
                str_contains($body['description'] ?? '', 'CHARGE_ALREADY_REFUNDED');
            if ($alreadyRefunded) {
                return true;
            }
            throw new TelegramError($method, (int) ($body['error_code'] ?? 0));
        }
        return $body['result'];
    }

    private function checkpoint(): void
    {
        $this->state?->save($this->orders->db, $this->dbPath);
    }

    private function send(int $chat, string $text, array $buttons = []): void
    {
        $args = ['chat_id' => $chat, 'text' => $text, 'parse_mode' => 'HTML',
            'link_preview_options' => ['is_disabled' => true]];
        if ($buttons) {
            $args['reply_markup'] = ['inline_keyboard' => array_map(
                static fn(array $button): array => [isset($button['url']) ? $button :
                    ['text' => $button[0], 'callback_data' => $button[1]]], $buttons)];
        }
        $this->api('sendMessage', $args);
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function supportButton(?string $order = null): array
    {
        $url = ctype_digit($this->support)
            ? 'tg://user?id=' . $this->support
            : 'https://t.me/' . $this->support;
        if ($order !== null && !ctype_digit($this->support)) {
            $url .= '?text=' . rawurlencode('Hi Crowdio, I need help with order ' . $order . '.');
        }
        return ['text' => '💬 Contact Support', 'url' => $url];
    }

    private function customerButtons(?string $order = null): array
    {
        return [$this->supportButton($order), self::MINE, self::HOME];
    }

    private function price(int $stars, int $quantity): string
    {
        return '⭐ ' . number_format($stars) . ' Stars' . ($quantity === 1000 ? ' (' . self::REFERENCE . ')' : '');
    }

    private function quantity(array $row, string $prefix = '🎁 '): string
    {
        if ($row['purchased'] === null) {
            return $this->escape($row['product']);
        }
        if ($row['bonus'] > 0) {
            return $prefix . number_format($row['purchased']) . ' purchased + ' . number_format($row['bonus']) .
                ' bonus = ' . number_format($row['total_members']) . ' channel members';
        }
        return Orders::packageTitle($row['purchased']);
    }

    private function card(array $row): string
    {
        [$heading, $status] = match ($row['status']) {
            'queued' => ['✅ Payment received', 'Awaiting delivery'],
            'processing' => ['⏳ Order in progress', 'In progress'],
            'completed' => ['✅ Order completed', 'Completed'],
            'refunded' => ['↩️ Payment refunded', 'Refunded'],
            'cancelled' => ['📦 Your order', 'Cancelled'],
            'awaiting_payment' => ['📦 Your order', 'Waiting for payment'],
            'draft' => ['📦 Your order', 'Waiting for your channel'],
            default => ['📦 Your order', 'Ready to review'],
        };
        if ($row['status'] === 'awaiting_payment' && $row['invoice_expires'] <= time()) {
            $status = 'Invoice closed';
        }
        $text = "<b>$heading</b>\n\nOrder ID: <code>" . $this->escape($row['id']) . "</code>\n" .
            $this->quantity($row) . "\nChannel: " . $this->escape($row['target'] ?? 'Pending') .
            "\nStars: " . number_format($row['stars']) . "\nStatus: <b>$status</b>";
        if ($row['status'] === 'queued') {
            $text .= "\n\nOur team will deliver your order manually. We’ll let you know when delivery starts.";
        }
        return $text . "\n\nFor help, contact support and share your Order ID.";
    }

    private function review(int $user, array $row, bool $changed = false): void
    {
        $text = "<b>Review your order</b>\n\n" . $this->quantity($row) .
            "\nChannel: " . $this->escape($row['target']) . "\n" . $this->price($row['stars'], $row['purchased']) .
            "\nPayment is in Telegram Stars.\n";
        if ($row['bonus'] > 0) {
            $text .= "\nLaunch bonus for eligible first orders, while spots remain.\n";
        }
        if ($changed) {
            $text .= "\nYour order details changed. Review them before continuing.\n";
        }
        $text .= "\nCheck your channel and read /terms before paying.";
        $id = $row['id'];
        $this->send($user, $text, [
            ['Continue to Stars payment', "pay:$id:" . $row['bonus'] . ':' . $row['stars']],
            ['Edit Channel', "edit:$id"], ['Cancel Order', "cancel:$id"],
        ]);
    }

    private function invoice(int $user, string $id, int $bonus, int $stars): void
    {
        if (microtime(true) >= $this->acceptUntil) {
            $this->send($user, 'Checkout is temporarily unavailable. Please try again later.', $this->customerButtons());
            return;
        }
        // The callback price is a review version check, never the source of the charge.
        $row = $this->orders->invoice($user, $id, $this->prices, $bonus, $stars);
        if (!$row) {
            $this->send($user, 'This review has closed. Check My Orders or start a new order.', [self::MINE, self::NEW]);
            return;
        }
        if ($row['status'] === 'ready') {
            $this->review($user, $row, true);
            return;
        }
        $this->checkpoint();
        $buttons = [['Edit Channel', "edit:$id"], ['Cancel Order', "cancel:$id"], self::MINE];
        try {
            $this->api('sendInvoice', [
                'chat_id' => $user, 'title' => $row['product'],
                'description' => $this->quantity($row, '') . "\nChannel: " . $row['target'] .
                    "\n" . number_format($row['stars']) . ' Stars' .
                    ($row['purchased'] === 1000 ? ' (' . self::REFERENCE . ')' : '') .
                    "\nPayment is in Telegram Stars. Order ID: $id",
                'payload' => $id, 'provider_token' => '', 'currency' => 'XTR',
                'start_parameter' => 'order-' . $id,
                'prices' => [['label' => $row['product'], 'amount' => $row['stars']]],
            ]);
        } catch (TelegramError) {
            $this->send($user, 'If the invoice did not open, choose Edit Channel to restart checkout or Cancel Order.', $buttons);
            return;
        }
        $this->send($user, 'Pay using the invoice above. To change your channel or cancel before checkout, use the buttons below.', $buttons);
    }

    private function adminButtons(string $id): array
    {
        return [['Start processing', "process:$id"], ['Complete', "complete:$id"]];
    }

    private function notify(): void
    {
        $pending = $this->orders->query("SELECT * FROM orders WHERE status IN ('queued','processing','completed')
            AND charge NOT LIKE 'demo-%' AND (notified=0 OR customer_notified=0) ORDER BY created");
        foreach ($pending as $row) {
            if (!$row['customer_notified']) {
                try {
                    $this->send($row['user'], $this->card($row), $this->customerButtons($row['id']));
                    $this->orders->execute('UPDATE orders SET customer_notified=1 WHERE id=?', [$row['id']]);
                    $this->checkpoint();
                } catch (TelegramError) {
                    error_log('Customer receipt pending; will retry.');
                }
            }
            if (!$row['notified']) {
                try {
                    $this->send($this->admin, "<b>New paid order</b>\nUser: " . $row['user'] . "\n" .
                        $this->card($row), $this->adminButtons($row['id']));
                    $this->orders->execute('UPDATE orders SET notified=1 WHERE id=?', [$row['id']]);
                    $this->checkpoint();
                } catch (TelegramError) {
                    error_log('Admin order notice pending; will retry.');
                }
            }
        }
        foreach ($this->orders->query('SELECT * FROM rejected_payments WHERE refunded<2') as $row) {
            try {
                if (!$row['refunded']) {
                    $this->api('refundStarPayment', ['user_id' => $row['user'], 'telegram_payment_charge_id' => $row['charge']]);
                    $this->orders->execute('UPDATE rejected_payments SET refunded=1 WHERE charge=?', [$row['charge']]);
                    $this->checkpoint();
                }
                $this->send($row['user'], 'This payment was refunded because the invoice could no longer be used. ' .
                    'Check My Orders before trying again.', $this->customerButtons($row['order_id']));
                $this->orders->execute('UPDATE rejected_payments SET refunded=2 WHERE charge=?', [$row['charge']]);
                $this->checkpoint();
            } catch (TelegramError) {
                error_log('Payment reconciliation pending; will retry.');
            }
        }
    }

    private function callback(int $user, string $data): void
    {
        $parts = explode(':', $data);
        $action = $parts[0];
        $id = $parts[1] ?? '';
        switch ($action) {
            case 'home':
                $this->send($user, "<b>Crowdio</b>\nOrder Telegram channel members in a few taps.\n\n" .
                    'Choose a package, add your public channel and pay with Stars.',
                    [self::NEW, self::MINE, $this->supportButton(), ['Help & Terms', 'help']]);
                return;
            case 'new':
                $buttons = [];
                $lines = [];
                $offerAvailable = $this->orders->offerAvailable($user, time(), '');
                foreach ($this->prices as $quantity => $price) {
                    if ($price > 0) {
                        $label = number_format($quantity) . ' members';
                        $line = '<b>' . $label . '</b> · ' . $this->price($price, $quantity);
                        if ($offerAvailable && in_array($quantity, Orders::BONUS_PACKAGES, true)) {
                            $line .= "\n🎁 +" . number_format(Orders::BONUS) . ' bonus members on your first order · ' .
                                number_format($quantity + Orders::BONUS) . ' members total';
                        }
                        $lines[] = $line;
                        $buttons[] = [$label, "pick:$quantity"];
                    }
                }
                $buttons[] = self::HOME;
                $this->send($user, "<b>📦 Choose your channel package</b>\n\n" .
                    implode("\n\n", $lines) . "\n\nPayment is in Telegram Stars.", $buttons);
                return;
            case 'pick':
            case 'edit':
                // The original one-package button can still exist in older chats.
                $quantity = $action === 'pick' ? ($id === '' ? 1000 : (int) $id) : 0;
                $draft = $this->orders->draft($user, $this->prices, $quantity, $action === 'edit' ? $id : null);
                if (!$draft) {
                    $this->send($user, 'This order can’t be changed right now. Check My Orders or contact support.',
                        $this->customerButtons($id ?: null));
                    return;
                }
                $this->send($user, '<b>Send your public Telegram channel link or @username.</b>',
                    [['Cancel Order', "cancel:$draft"], self::HOME]);
                return;
            case 'cancel':
                $changed = $this->orders->execute("UPDATE orders SET status='cancelled' WHERE id=? AND user=?
                    AND purchased IS NOT NULL AND (status IN ('draft','ready') OR
                    (status='awaiting_payment' AND (checkout_id IS NULL OR invoice_expires<=?)))",
                    [$id, $user, time()])->rowCount();
                $this->send($user, $changed ? 'Order cancelled. You can start a new order anytime.' :
                    'This order can’t be cancelled here. Check My Orders or contact support.', $this->customerButtons());
                return;
            case 'pay':
                if (count($parts) === 4) {
                    $this->invoice($user, $id, (int) $parts[2], (int) $parts[3]);
                } else {
                    $this->send($user, 'This checkout has closed. Open New Order to try again.', $this->customerButtons());
                }
                return;
            case 'mine':
                $rows = $this->orders->query("SELECT * FROM orders WHERE user=? AND (charge IS NULL OR charge NOT LIKE 'demo-%')
                    ORDER BY created DESC, rowid DESC LIMIT 10", [$user]);
                if (!$rows) {
                    $this->send($user, 'No orders yet. Choose a package to get started.', [self::NEW, self::HOME]);
                }
                foreach ($rows as $row) {
                    $buttons = $this->customerButtons($row['id']);
                    if ($row['purchased'] !== null && in_array($row['status'], ['ready', 'awaiting_payment'], true)
                        && ($row['checkout_id'] === null || $row['invoice_expires'] <= time())) {
                        array_unshift($buttons, ['Edit Channel', 'edit:' . $row['id']], ['Cancel Order', 'cancel:' . $row['id']]);
                    }
                    $this->send($user, $this->card($row), $buttons);
                }
                return;
            case 'help':
                $terms = trim((string) getenv('TERMS_TEXT'));
                if ($terms === '' || $terms === 'Configure delivery time, refund and refill terms before accepting money.') {
                    $terms = self::TERMS;
                }
                $this->send($user, "<b>Help &amp; Terms</b>\n\n" . $this->escape($terms), $this->customerButtons());
                return;
            case 'process':
            case 'complete':
                if ($user !== $this->admin) {
                    return;
                }
                $state = $action === 'process' ? 'processing' : 'completed';
                $from = $action === 'process' ? "'queued'" : "'queued','processing'";
                $changed = $this->orders->execute("UPDATE orders SET status=? WHERE id=? AND status IN ($from)
                    AND charge IS NOT NULL AND charge NOT LIKE 'demo-%'", [$state, $id])->rowCount();
                if ($changed) {
                    $this->checkpoint();
                    $row = $this->orders->order($id);
                    $this->send($user, 'Order ' . $this->escape($id) . ': ' . $state);
                    $this->send($row['user'], $this->card($row), $this->customerButtons($id));
                }
                return;
            default:
                $this->send($user, 'That option has closed. Open the main menu to continue.', [self::HOME]);
        }
    }

    private function handle(array $update): void
    {
        if (isset($update['pre_checkout_query'])) {
            $query = $update['pre_checkout_query'];
            $ok = microtime(true) < $this->acceptUntil &&
                $this->orders->checkout($query);
            if ($ok) {
                $this->checkpoint();
            }
            $args = ['pre_checkout_query_id' => $query['id'], 'ok' => $ok];
            if (!$ok) {
                $args['error_message'] = 'This invoice can no longer be used. Check My Orders or start a new order.';
            }
            try {
                $this->api('answerPreCheckoutQuery', $args, 4);
            } catch (TelegramError $error) {
                if ($error->getCode() !== 400) {
                    throw $error;
                }
            }
            return;
        }
        if (isset($update['callback_query'])) {
            $query = $update['callback_query'];
            try {
                $this->api('answerCallbackQuery', ['callback_query_id' => $query['id']]);
            } catch (TelegramError $error) {
                if ($error->getCode() !== 400) {
                    throw $error;
                }
            }
            if (isset($query['data']) && ($query['message']['chat']['type'] ?? '') === 'private') {
                $this->callback($query['from']['id'], $query['data']);
            }
            return;
        }
        $message = $update['message'] ?? [];
        if (($message['chat']['type'] ?? '') !== 'private' || !isset($message['from']['id'])) {
            return;
        }
        $user = $message['from']['id'];
        if (isset($message['successful_payment'])) {
            $this->orders->paid($user, $message['successful_payment'], $message['date']);
            return;
        }
        $text = trim($message['text'] ?? '');
        $command = strtok($text, " \t\n");
        switch ($command) {
            case '/start':
            case '/orders':
            case '/terms':
                $this->callback($user, ['/start' => 'home', '/orders' => 'mine', '/terms' => 'help'][$command]);
                return;
            case '/support':
            case '/paysupport':
                $this->send($user, "<b>💬 Crowdio Support</b>\n\nOpen a chat with support and share the Order ID from My Orders.",
                    $this->customerButtons());
                return;
            case '/queue':
                if ($user === $this->admin) {
                    $rows = $this->orders->query("SELECT * FROM orders WHERE status IN ('queued','processing')
                        AND charge NOT LIKE 'demo-%' ORDER BY created LIMIT 20");
                    foreach ($rows as $row) {
                        $this->send($user, $this->card($row), $this->adminButtons($row['id']));
                    }
                    if (!$rows) {
                        $this->send($user, 'Queue is empty.');
                    }
                }
                return;
        }
        $draft = $this->orders->first("SELECT id FROM orders WHERE user=? AND status='draft' AND purchased IS NOT NULL", [$user]);
        if (!$draft) {
            $this->callback($user, 'home');
            return;
        }
        if (!preg_match('~^(?:https://t\.me/|@)([A-Za-z][A-Za-z0-9_]{4,31})/?$~D', $text, $match)) {
            $this->send($user, 'Send the public channel link from Telegram or its @username.',
                [['Cancel Order', 'cancel:' . $draft['id']], self::HOME]);
            return;
        }
        $row = $this->orders->review($user, '@' . $match[1], $this->prices);
        if ($row) {
            $this->review($user, $row);
        } else {
            $this->send($user, 'This package is currently unavailable. Choose another package to continue.', [self::NEW]);
        }
    }

    public function run(int $seconds): void
    {
        if (!empty($this->api('getWebhookInfo')['url'])) {
            throw new RuntimeException('An existing webhook must be removed before polling');
        }
        $this->api('getMe');
        $this->checkpoint();
        $deadline = $seconds ? microtime(true) + $seconds : INF;
        $this->acceptUntil = $deadline - 30;
        $offset = (int) ($this->orders->first("SELECT value FROM settings WHERE key='offset'")['value'] ?? 0);
        $needsCheckpoint = false;
        while (microtime(true) < $deadline) {
            try {
                if ($needsCheckpoint) {
                    $this->checkpoint();
                    $needsCheckpoint = false;
                }
                $updates = $this->api('getUpdates', ['offset' => $offset, 'timeout' => 10,
                    'allowed_updates' => ['message', 'callback_query', 'pre_checkout_query']], 15);
                foreach ($updates as $update) {
                    try {
                        $this->handle($update);
                    } catch (TelegramError $error) {
                        // A blocked chat or malformed outbound message must not jam the payment queue.
                        if (!in_array($error->getCode(), [400, 403], true)) {
                            throw $error;
                        }
                    }
                    $next = $update['update_id'] + 1;
                    $this->orders->execute("INSERT OR REPLACE INTO settings VALUES('offset',?)", [$next]);
                    $offset = $next;
                    $needsCheckpoint = true;
                    $this->checkpoint();
                    $needsCheckpoint = false;
                }
                $this->notify();
            } catch (Throwable $error) {
                error_log('Bot request or storage operation failed; will retry.');
                sleep(2);
            }
        }
        $this->checkpoint();
    }
}

function main(): void
{
    umask(0077);
    ini_set('display_errors', '0');
    ini_set('zend.exception_ignore_args', '1');
    $token = trim((string) getenv('BOT_TOKEN'));
    $admin = filter_var(getenv('ADMIN_ID'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $support = trim((string) (getenv('SUPPORT_CONTACT') ?: getenv('SUPPORT')));
    $publicSupport = preg_match('~^(?:https://t\.me/|t\.me/|@)?([A-Za-z][A-Za-z0-9_]{4,31})/?$~D', $support, $match)
        && strcasecmp($match[1], 'your_support_username') !== 0;
    if ($publicSupport) {
        $support = $match[1];
    } elseif (!ctype_digit($support) || !filter_var($support, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) {
        fwrite(STDERR, "Set SUPPORT_CONTACT or SUPPORT to the Crowdio support username, t.me link or numeric user ID.\n");
        exit(1);
    }
    if (!preg_match('/^\d+:[A-Za-z0-9_-]+$/D', $token) || !$admin) {
        fwrite(STDERR, "Set BOT_TOKEN and numeric ADMIN_ID.\n");
        exit(1);
    }
    $rawStars = trim((string) getenv('MEMBER_PACKAGE_PRICE_STARS'));
    $stars = filter_var($rawStars, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
    if (!$stars) {
        fwrite(STDERR, "The 1,000-member package is unavailable until MEMBER_PACKAGE_PRICE_STARS is set.\n");
    }
    $path = getenv('DB_PATH') ?: __DIR__ . '/data/orders.sqlite3';
    if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0700, true) && !is_dir(dirname($path))) {
        throw new RuntimeException('Cannot create database directory');
    }
    $lock = fopen($path . '.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Database is already in use');
    }
    $repo = getenv('STATE_REPOSITORY');
    $githubToken = getenv('STATE_GITHUB_TOKEN');
    if ((bool) $repo !== (bool) $githubToken) {
        throw new RuntimeException('Incomplete remote state settings');
    }
    $state = $repo && $githubToken ? new GitHubState($token, $repo, $githubToken) : null;
    $state?->restore($path);
    $prices = [500 => 193, 1000 => $stars ?: 0, 5000 => 1925];
    $bot = new Bot($token, $admin, $prices, $support, new Orders($path), $state, $path);
    $bot->run(max(0, (int) getenv('RUN_SECONDS')));
}

try {
    main();
} catch (Throwable $error) {
    fwrite(STDERR, "Crowdio could not start or finish safely. Check PHP extensions, database, worker and service configuration.\n");
    exit(1);
}
