"""Small configurable Telegram order bot; demo mode never accepts real payments."""
import json
import logging
import os
import re
import sqlite3
import time
import uuid
from html import escape
from pathlib import Path
from urllib.request import Request, urlopen
from urllib.error import HTTPError

LOG = logging.getLogger('orders')
DEFAULT_TERMS = ('Orders are delivered manually to the public channel you provide. '
                 'Review your channel and package before paying. '
                 'This package does not include post views, reactions or guaranteed engagement. '
                 'Contact support for delivery, cancellation, refill and refund requests.')


def target(text):
    match = re.fullmatch(r'(?:https://t\.me/|@)([A-Za-z][A-Za-z0-9_]{4,31})/?', text.strip())
    return '@' + match[1] if match else None


class Bot:
    def __init__(self, token, admin, db_path='data/orders.sqlite3', demo=True, catalog='catalog.json'):
        admin_text = str(admin).strip()
        if not admin_text.isascii() or not admin_text.isdecimal() or int(admin_text) <= 0:
            raise SystemExit('ADMIN_ID must be your positive numeric Telegram user ID, not a username or bot ID. Update the GitHub Actions secret ADMIN_ID.')
        self.token, self.admin, self.demo = token.strip(), int(admin_text), demo
        self.catalog = json.loads(Path(catalog).read_text())
        self.state_store = None
        if os.getenv('STATE_REPOSITORY') and os.getenv('STATE_GITHUB_TOKEN'):
            from state_store import GitHubState
            self.state_store = GitHubState(self.token, os.environ['STATE_REPOSITORY'], os.environ['STATE_GITHUB_TOKEN'])
            self.state_store.restore(db_path)
        assert self.admin > 0
        assert all(isinstance(p['stars'], int) and p['stars'] > 0 for p in self.catalog)
        Path(db_path).parent.mkdir(parents=True, exist_ok=True)
        self.db = sqlite3.connect(db_path)
        self.db.row_factory = sqlite3.Row
        self.db.executescript('''
        CREATE TABLE IF NOT EXISTS orders (
          id TEXT PRIMARY KEY, user INTEGER NOT NULL, product TEXT NOT NULL,
          stars INTEGER NOT NULL, target TEXT, status TEXT NOT NULL,
          charge TEXT UNIQUE, notified INTEGER NOT NULL DEFAULT 0,
          created INTEGER NOT NULL);
        CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value INTEGER);
        ''')
        if 'customer_notified' not in [r[1] for r in self.db.execute('PRAGMA table_info(orders)')]:
            self.db.execute('ALTER TABLE orders ADD COLUMN customer_notified INTEGER NOT NULL DEFAULT 0')
            self.db.commit()

    def checkpoint(self):
        if self.state_store:
            self.state_store.save(self.db)

    def api(self, method, **params):
        req = Request('https://api.telegram.org/bot' + self.token + '/' + method,
                      data=json.dumps(params).encode(), headers={'Content-Type': 'application/json'})
        with urlopen(req, timeout=45) as response:
            body = json.load(response)
        if not body.get('ok'):
            raise RuntimeError('Telegram API request failed: ' + method)
        return body['result']

    def send(self, chat, text, buttons=None):
        params = {'chat_id': chat, 'text': text, 'parse_mode': 'HTML',
                  'link_preview_options': {'is_disabled': True}}
        if buttons:
            params['reply_markup'] = {'inline_keyboard': [
                [button if isinstance(button, dict) else
                 {'text': button[0], 'callback_data': button[1]}] for button in buttons]}
        return self.api('sendMessage', **params)

    def support_contact(self):
        raw = (os.getenv('SUPPORT_CONTACT') or os.getenv('SUPPORT') or '').strip()
        name = re.fullmatch(r'(?:https://t\.me/|t\.me/|@)?([A-Za-z][A-Za-z0-9_]{4,31})/?', raw)
        if name:
            return '@' + name[1], 'https://t.me/' + name[1]
        if raw.isascii() and raw.isdecimal() and int(raw) > 0:
            return 'Crowdio Support', 'tg://user?id=' + raw
        return 'Crowdio Support', 'tg://user?id=' + str(self.admin)

    def customer_buttons(self):
        return [{'text': '💬 Contact Support', 'url': self.support_contact()[1]},
                ('📦 My Orders', 'mine'), ('Main Menu', 'home')]

    def status_card(self, row):
        labels = {'draft': 'Waiting for your channel link', 'ready': 'Ready to confirm',
                  'awaiting_payment': 'Waiting for payment', 'queued': 'Awaiting delivery',
                  'processing': 'In progress', 'completed': 'Completed', 'cancelled': 'Cancelled'}
        status = labels.get(row['status'], row['status'])
        heading = {'queued': '✅ Payment received', 'processing': '⏳ Order in progress',
                   'completed': '✅ Order completed', 'refunded': '↩️ Payment refunded'}.get(row['status'], '📦 Your order')
        text = (f"<b>{heading}</b>\n\nOrder ID: <code>{escape(row['id'])}</code>\n"
                f"Package: {escape(row['product'])}\nChannel: {escape(row['target'] or 'Not provided')}\n"
                f"Total: {row['stars']:,} Stars\nStatus: <b>{escape(status)}</b>\n")
        if row['status'] == 'queued':
            text += '\nYour order is confirmed and waiting for our team.\nWe will notify you when delivery starts.\n'
        elif row['status'] == 'processing':
            text += '\nOur team is working on your order. We will notify you when it is complete.\n'
        elif row['status'] == 'completed':
            text += '\nYour order is complete. Thank you for choosing Crowdio.\n'
        if self.demo:
            text += '\nDemo order: no payment was taken and no members will be delivered.\n'
        return text + '\nSupport: ' + escape(self.support_contact()[0]) + '\nFor help, tap Contact Support and share your Order ID.'

    def order(self, ident):
        return self.db.execute('SELECT * FROM orders WHERE id=?', (ident,)).fetchone()

    def home(self, user):
        self.send(user, ('DEMO — no money will be charged.\n' if self.demo else '') +
                  '<b>Crowdio</b>\nTelegram member packages, ordered in a few taps.\n\n'
                  'Choose a package → Add your channel → Pay with Stars\n\n'
                  'Track your order and reach support right here.',
                  [('🛒 New Order', 'new'), ('📦 My Orders', 'mine'),
                   {'text': '💬 Contact Support', 'url': self.support_contact()[1]}, ('Help & Terms', 'help')])

    def paid(self, ident, user, currency, amount, charge):
        row = self.order(ident)
        if not row or row['user'] != user or currency != 'XTR' or amount != row['stars']:
            raise ValueError('Payment mismatch; manual reconciliation required')
        if row['charge'] == charge:
            self.checkpoint()
            return  # Telegram can redeliver successful_payment.
        if row['status'] != 'awaiting_payment':
            if not self.demo:
                self.api('refundStarPayment', user_id=user, telegram_payment_charge_id=charge)
            return
        with self.db:
            self.db.execute("UPDATE orders SET status='queued', charge=? WHERE id=?", (charge, ident))
        self.checkpoint()

    def notifications(self):
        # Retry unacknowledged admin notifications on the next poll. No customer funds are lost.
        rows = self.db.execute("SELECT * FROM orders WHERE status='queued' AND (notified=0 OR customer_notified=0)").fetchall()
        for row in rows:
            if not row['customer_notified']:
                self.send(row['user'], self.status_card(row), self.customer_buttons())
                with self.db:
                    self.db.execute('UPDATE orders SET customer_notified=1 WHERE id=?', (row['id'],))
                self.checkpoint()
            if row['notified']:
                continue
            self.send(self.admin, f"<b>New paid order</b>\nOrder ID: <code>{row['id']}</code>\nUser: {row['user']}\n{escape(row['product'])}\n"
                      f"Target: {row['target']}\n{row['stars']} Stars\nStatus: queued",
                      [('Start processing', 'process:' + row['id']), ('Complete', 'complete:' + row['id'])])
            with self.db:
                self.db.execute('UPDATE orders SET notified=1 WHERE id=?', (row['id'],))
            self.checkpoint()

    def callback(self, q):
        user, data = q['from']['id'], q['data']
        if not q.get('command'):
            try:
                self.api('answerCallbackQuery', callback_query_id=q['id'])
            except HTTPError as exc:
                if exc.code != 400:
                    raise  # An expired button acknowledgement must not block later payments.
        if data == 'home':
            self.home(user)
        elif data == 'new':
            self.send(user, '<b>Choose your package</b>\n\nTelegram channel members. Prices are in Stars.',
                      [(f"{p['title']} · ⭐ {p['stars']:,}", 'pick:' + str(i)) for i, p in enumerate(self.catalog)] + [('Main Menu', 'home')])
        elif data == 'mine':
            rows = self.db.execute('SELECT * FROM orders WHERE user=? ORDER BY created DESC LIMIT 10', (user,)).fetchall()
            if not rows:
                self.send(user, '<b>No orders yet</b>\nChoose a package to get started.', [('🛒 New Order', 'new')])
            for row in rows:
                self.send(user, self.status_card(row), self.customer_buttons())
        elif data == 'help':
            self.terms(user)
        elif data.startswith('pick:'):
            try:
                index = int(data.split(':')[1])
                if index < 0:
                    return
                product = self.catalog[index]
            except (ValueError, IndexError):
                return
            ident = uuid.uuid4().hex[:16]
            with self.db:
                self.db.execute("UPDATE orders SET status='cancelled' WHERE user=? AND status='draft'", (user,))
                self.db.execute('INSERT INTO orders(id,user,product,stars,status,created) VALUES(?,?,?,?,?,?)',
                                (ident, user, product['title'], product['stars'], 'draft', int(time.time())))
            self.checkpoint()
            self.send(user, f"<b>{escape(product['title'])}</b>\n⭐ {product['stars']:,} Stars\n\n" +
                      escape(product['description']) + '\n\n<b>Send your channel link</b>\n'
                      '<code>https://t.me/yourchannel</code> or <code>@yourchannel</code>', [('Change Package', 'new')])
        elif data.startswith('edit:'):
            row = self.order(data.split(':')[1])
            if row and row['user'] == user and row['status'] == 'ready':
                with self.db:
                    self.db.execute("UPDATE orders SET status='draft' WHERE id=?", (row['id'],))
                self.checkpoint()
                self.send(user, 'Send the correct public channel link or @username.')
        elif data.startswith('pay:'):
            row = self.order(data.split(':')[1])
            if not row or row['user'] != user or row['status'] != 'ready':
                return
            if self.demo:
                with self.db:
                    self.db.execute("UPDATE orders SET status='awaiting_payment' WHERE id=?", (row['id'],))
                self.paid(row['id'], user, 'XTR', row['stars'], 'demo-' + row['id'])
            else:
                if time.monotonic() >= getattr(self, 'accept_until', float('inf')):
                    return self.send(user, 'Ordering is currently unavailable. Please contact support.', self.customer_buttons())
                # Mark first to prevent a duplicate callback issuing a second invoice.
                with self.db:
                    self.db.execute("UPDATE orders SET status='awaiting_payment' WHERE id=?", (row['id'],))
                self.checkpoint()
                self.api('sendInvoice', chat_id=user, title=row['product'][:32],
                         description=f"Channel: {row['target']}\nOrder ID: {row['id']}\nYour order enters the delivery queue after payment.",
                         payload=row['id'], provider_token='', currency='XTR',
                         prices=[{'label': row['product'], 'amount': row['stars']}])
        elif data.startswith(('process:', 'complete:')) and user == self.admin:
            action, ident = data.split(':')
            row = self.order(ident)
            if not row:
                return
            state = 'processing' if action == 'process' else 'completed'
            allowed = ('queued',) if action == 'process' else ('queued', 'processing')
            if row['status'] not in allowed:
                return
            with self.db:
                self.db.execute('UPDATE orders SET status=? WHERE id=?', (state, ident))
            self.checkpoint()
            self.send(user, f'Order {ident}: {state}')
            self.send(row['user'], self.status_card(self.order(ident)), self.customer_buttons())

    def terms(self, user):
        self.send(user, '<b>Help &amp; Terms</b>\n\n' + escape(os.getenv('TERMS_TEXT') or DEFAULT_TERMS) +
                  ('\n\nDemo mode: no payment or delivery.' if self.demo else '') +
                  '\n\nSupport: ' + escape(self.support_contact()[0]), self.customer_buttons())

    def support(self, user):
        self.send(user, '<b>💬 Crowdio Support</b>\n\n'
                  'Tap Contact Support below and share your Order ID.\n'
                  'Find your Order ID in My Orders.\n\n' + escape(self.support_contact()[0]),
                  self.customer_buttons())

    def handle(self, update):
        if 'pre_checkout_query' in update:
            q = update['pre_checkout_query']
            row = self.order(q['invoice_payload'])
            ok = bool(not self.demo and row and row['user'] == q['from']['id'] and
                      row['status'] == 'awaiting_payment' and q['currency'] == 'XTR' and
                      q['total_amount'] == row['stars'])
            ok = ok and time.monotonic() < getattr(self, 'accept_until', float('inf'))
            args = {'pre_checkout_query_id': q['id'], 'ok': ok}
            if not ok:
                args['error_message'] = 'Order is invalid or already paid. Please contact support.'
            try:
                self.api('answerPreCheckoutQuery', **args)
            except HTTPError as exc:
                if exc.code != 400:
                    raise
            return
        if 'callback_query' in update:
            return self.callback(update['callback_query'])
        m = update.get('message', {})
        if m.get('chat', {}).get('type') != 'private':
            return
        user = m['from']['id']
        if 'successful_payment' in m:
            p = m['successful_payment']
            return self.paid(p['invoice_payload'], user, p['currency'], p['total_amount'], p['telegram_payment_charge_id'])
        text = m.get('text', '')
        if text.startswith('/start'):
            self.home(user)
        elif text in ('/support', '/paysupport'):
            self.support(user)
        elif text == '/terms':
            self.terms(user)
        elif text == '/orders':
            self.callback({'id': 'command', 'from': {'id': user}, 'data': 'mine', 'command': True})
        elif text == '/queue' and user == self.admin:
            rows = self.db.execute("SELECT * FROM orders WHERE status IN ('queued','processing') ORDER BY created LIMIT 20").fetchall()
            for row in rows:
                self.send(user, f"{row['id']} | {row['product']} | {row['target']} | {row['status']}",
                          [('Start processing', 'process:' + row['id']), ('Complete', 'complete:' + row['id'])])
            if not rows:
                self.send(user, 'Queue is empty.')
        else:
            row = self.db.execute("SELECT * FROM orders WHERE user=? AND status='draft' ORDER BY created DESC LIMIT 1", (user,)).fetchone()
            if not row:
                return self.home(user)
            link = target(text)
            if not link:
                return self.send(user, 'That link does not look right. Send your public channel link or username.\nExample: https://t.me/yourchannel or @yourchannel')
            with self.db:
                self.db.execute("UPDATE orders SET target=?,status='ready' WHERE id=?", (link, row['id']))
            self.checkpoint()
            self.send(user, f"<b>Review your order</b>\n\nPackage: {escape(row['product'])}\nChannel: {link}\nTotal: ⭐ {row['stars']:,} Stars\n\n"
                      'Check your channel before paying.\nBy continuing, you agree to /terms.',
                      [('Confirm Demo Order' if self.demo else f"Pay {row['stars']:,} Stars", 'pay:' + row['id']),
                       ('Edit Channel', 'edit:' + row['id']), ('Change Package', 'new')])

    def run(self, seconds=0):
        # Refuse to silently steal traffic from an existing webhook.
        if self.api('getWebhookInfo').get('url'):
            raise RuntimeError('Webhook exists. Remove it explicitly before using polling.')
        self.api('getMe')
        self.checkpoint()  # Verify storage access before offering any live invoice.
        LOG.info('Bot connected; demo=%s; support configured=%s', self.demo,
                 bool((os.getenv('SUPPORT_CONTACT') or os.getenv('SUPPORT') or '').strip()))
        deadline = time.monotonic() + seconds if seconds else float('inf')
        self.accept_until = deadline - 30 if seconds else float('inf')
        saved = self.db.execute("SELECT value FROM settings WHERE key='offset'").fetchone()
        offset = saved[0] if saved else 0
        while time.monotonic() < deadline:
            try:
                updates = self.api('getUpdates', offset=offset, timeout=20,
                                   allowed_updates=['message', 'callback_query', 'pre_checkout_query'])
                for update in updates:
                    self.handle(update)
                    next_offset = update['update_id'] + 1
                    with self.db:
                        self.db.execute("INSERT OR REPLACE INTO settings VALUES('offset',?)", (next_offset,))
                    self.checkpoint()
                    offset = next_offset
                self.notifications()
            except Exception as exc:
                # Never log URLs, tokens, payment payloads or customer data.
                LOG.error('Request or update failed (%s); will retry.', type(exc).__name__)
                time.sleep(3)
        self.checkpoint()


if __name__ == '__main__':
    logging.basicConfig(level=logging.INFO)
    demo = os.getenv('DEMO_MODE', '0') == '1'
    Bot(os.environ['BOT_TOKEN'], os.environ['ADMIN_ID'],
        os.getenv('DB_PATH', 'data/orders.sqlite3'), demo).run(int(os.getenv('RUN_SECONDS', '0')))
