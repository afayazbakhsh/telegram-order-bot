<?php
declare(strict_types=1);

final class Orders
{
    public static function packageTitle(int $quantity): string
    {
        return number_format($quantity) . ' Telegram channel members';
    }
    public const BONUS = 500;
    public const BONUS_PACKAGES = [1000, 5000];
    public const OFFER_LIMIT = 10;
    public const INVOICE_SECONDS = 900;
    public const CHECKOUT_SECONDS = 3600;

    public readonly PDO $db;

    public function __construct(string $path)
    {
        $this->db = new PDO('sqlite:' . $path);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->db->exec('PRAGMA busy_timeout=3000');
        $this->write(function (): void {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS orders (
                  id TEXT PRIMARY KEY, user INTEGER NOT NULL, product TEXT NOT NULL,
                  stars INTEGER NOT NULL, target TEXT, status TEXT NOT NULL,
                  charge TEXT UNIQUE, notified INTEGER NOT NULL DEFAULT 0,
                  created INTEGER NOT NULL);
                CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value INTEGER);
                CREATE TABLE IF NOT EXISTS customer_activity (
                  user INTEGER PRIMARY KEY, first_seen INTEGER NOT NULL, last_seen INTEGER NOT NULL);
                CREATE TABLE IF NOT EXISTS rejected_payments (
                  charge TEXT PRIMARY KEY, user INTEGER NOT NULL, order_id TEXT NOT NULL,
                  refunded INTEGER NOT NULL DEFAULT 0);
            ");
            $columns = array_column($this->query('PRAGMA table_info(orders)'), 'name');
            foreach ([
                'customer_notified' => 'INTEGER NOT NULL DEFAULT 0',
                'purchased' => 'INTEGER', 'bonus' => 'INTEGER NOT NULL DEFAULT 0',
                'total_members' => 'INTEGER', 'offer_used' => 'INTEGER NOT NULL DEFAULT 0',
                'invoice_expires' => 'INTEGER', 'checkout_id' => 'TEXT', 'paid_at' => 'INTEGER', 'completed_at' => 'INTEGER',
            ] as $column => $type) {
                if (!in_array($column, $columns, true)) {
                    $this->db->exec("ALTER TABLE orders ADD COLUMN $column $type");
                }
            }
            $this->db->exec('CREATE UNIQUE INDEX IF NOT EXISTS offer_once_per_user ON orders(user) WHERE offer_used=1');
        });
    }

    public function execute(string $sql, array $args = []): PDOStatement
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($args);
        return $statement;
    }

    public function query(string $sql, array $args = []): array
    {
        return $this->execute($sql, $args)->fetchAll();
    }

    public function first(string $sql, array $args = []): ?array
    {
        return $this->execute($sql, $args)->fetch() ?: null;
    }

    public function recordActivity(int $user, int $at): void
    {
        $this->execute("INSERT INTO customer_activity VALUES(?,?,?)
            ON CONFLICT(user) DO UPDATE SET
              first_seen=MIN(first_seen,excluded.first_seen),
              last_seen=MAX(last_seen,excluded.last_seen)", [$user, $at, $at]);
    }

    public function order(string $id): ?array
    {
        return $this->first('SELECT * FROM orders WHERE id=?', [$id]);
    }

    public function write(callable $operation): mixed
    {
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $result = $operation();
            $this->db->exec('COMMIT');
            return $result;
        } catch (Throwable $error) {
            $this->db->exec('ROLLBACK');
            throw $error;
        }
    }

    public function offerAvailable(int $user, int $now, string $exclude): bool
    {
        // Older paid orders have a charge but no purchased quantity. Historical demo
        // rows used a synthetic demo- charge and do not count as a previous purchase.
        $prior = $this->first("SELECT 1 FROM orders WHERE user=? AND id<>? AND (
            (charge IS NOT NULL AND charge NOT LIKE 'demo-%') OR
            (status='awaiting_payment' AND invoice_expires>?)
        ) LIMIT 1", [$user, $exclude, $now]);
        $slots = $this->first("SELECT COUNT(*) AS count FROM orders WHERE offer_used=1 OR (
            bonus=500 AND status='awaiting_payment' AND invoice_expires>? AND id<>?
        )", [$now, $exclude]);
        return !$prior && $slots['count'] < self::OFFER_LIMIT;
    }

    private function packageBonus(array $row, int $now): int
    {
        return in_array($row['purchased'], self::BONUS_PACKAGES, true) &&
            $this->offerAvailable($row['user'], $now, $row['id']) ? self::BONUS : 0;
    }

    public function draft(int $user, array $prices, int $quantity, ?string $from = null): ?string
    {
        return $this->write(function () use ($user, $prices, $quantity, $from): ?string {
            $now = time();
            if ($this->first("SELECT 1 FROM orders WHERE user=? AND status='awaiting_payment'
                AND checkout_id IS NOT NULL AND invoice_expires>?", [$user, $now])) {
                return null;
            }
            if ($from !== null) {
                $row = $this->order($from);
                if (!$row || $row['user'] !== $user || $row['purchased'] === null ||
                    !in_array($row['status'], ['ready', 'awaiting_payment'], true)) {
                    return null;
                }
                $quantity = $row['purchased'];
            }
            $stars = $prices[$quantity] ?? 0;
            if (!$stars) {
                return null;
            }
            $this->execute("UPDATE orders SET status='cancelled' WHERE user=? AND (
                status IN ('draft','ready') OR (status='awaiting_payment' AND purchased IS NOT NULL)
            )", [$user]);
            $id = bin2hex(random_bytes(8));
            $this->execute("INSERT INTO orders(id,user,product,stars,status,created,purchased,total_members)
                VALUES(?,?,?,?,'draft',?,?,?)", [$id, $user, self::packageTitle($quantity), $stars, $now, $quantity, $quantity]);
            return $id;
        });
    }

    public function review(int $user, string $target, array $prices): ?array
    {
        return $this->write(function () use ($user, $target, $prices): ?array {
            $row = $this->first("SELECT * FROM orders WHERE user=? AND status='draft'
                AND purchased IS NOT NULL ORDER BY created DESC LIMIT 1", [$user]);
            $stars = $prices[$row['purchased'] ?? 0] ?? 0;
            if (!$row || !$stars) {
                return null;
            }
            $bonus = $this->packageBonus($row, time());
            $this->execute("UPDATE orders SET target=?,stars=?,bonus=?,total_members=?,status='ready'
                WHERE id=?", [$target, $stars, $bonus, $row['purchased'] + $bonus, $row['id']]);
            return $this->order($row['id']);
        });
    }

    public function invoice(int $user, string $id, array $prices, int $reviewedBonus, int $reviewedStars): ?array
    {
        return $this->write(function () use ($user, $id, $prices, $reviewedBonus, $reviewedStars): ?array {
            $row = $this->order($id);
            $stars = $prices[$row['purchased'] ?? 0] ?? 0;
            if (!$row || $row['user'] !== $user || $row['status'] !== 'ready' ||
                !$stars || !$row['target']) {
                return null;
            }
            $now = time();
            $bonus = $this->packageBonus($row, $now);
            if ($row['stars'] !== $stars || $row['bonus'] !== $bonus || $reviewedBonus !== $bonus || $reviewedStars !== $stars) {
                $this->execute('UPDATE orders SET stars=?,bonus=?,total_members=? WHERE id=?',
                    [$stars, $bonus, $row['purchased'] + $bonus, $id]);
            } else {
                $this->execute("UPDATE orders SET status='awaiting_payment',invoice_expires=? WHERE id=?",
                    [$now + self::INVOICE_SECONDS, $id]);
            }
            return $this->order($id);
        });
    }

    private function matches(?array $row, int $user, array $payment): bool
    {
        return $row && $row['user'] === $user && $payment['currency'] === 'XTR' &&
            $payment['total_amount'] === $row['stars'];
    }

    public function checkout(array $query): bool
    {
        return $this->write(function () use ($query): bool {
            $row = $this->order($query['invoice_payload']);
            $now = time();
            if (!$this->matches($row, $query['from']['id'], $query) ||
                $row['status'] !== 'awaiting_payment' || $row['invoice_expires'] <= $now) {
                return false;
            }
            if ($row['checkout_id'] !== null) {
                return $row['checkout_id'] === $query['id'];
            }
            $this->execute('UPDATE orders SET checkout_id=?,invoice_expires=? WHERE id=?',
                [$query['id'], $now + self::CHECKOUT_SECONDS, $row['id']]);
            return true;
        });
    }

    public function paid(int $user, array $payment, int $paidAt): void
    {
        $this->write(function () use ($user, $payment, $paidAt): void {
            $charge = $payment['telegram_payment_charge_id'];
            if ($this->first('SELECT 1 FROM orders WHERE charge=?', [$charge]) ||
                $this->first('SELECT 1 FROM rejected_payments WHERE charge=?', [$charge])) {
                return;
            }
            $id = $payment['invoice_payload'];
            $row = $this->order($id);
            $valid = $this->matches($row, $user, $payment) && $row['status'] === 'awaiting_payment';
            // Python invoices had no reservation. Reconcile any payment already
            // approved by the previous worker using the original persisted price.
            if ($valid && $row['purchased'] !== null) {
                $valid = $row['checkout_id'] !== null && $paidAt <= $row['invoice_expires'];
                if ($valid && $row['bonus'] > 0) {
                    $valid = $this->offerAvailable($user, time(), $id);
                }
            }
            if (!$valid) {
                $this->execute('INSERT OR IGNORE INTO rejected_payments(charge,user,order_id) VALUES(?,?,?)',
                    [$charge, $user, $id]);
                return;
            }
            $this->execute("UPDATE orders SET status='queued',charge=?,offer_used=?,paid_at=? WHERE id=?",
                [$charge, (int) ($row['bonus'] > 0), $paidAt, $id]);
        });
    }
}
