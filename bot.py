"""Small configurable Telegram order bot; demo mode never accepts real payments."""
import json
import logging
import os
import re
import sqlite3
import time
import uuid
from pathlib import Path
from urllib.request import Request, urlopen

LOG = logging.getLogger('orders')


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

    def api(self, method, **params):
        req = Request('https://api.telegram.org/bot' + self.token + '/' + method,
                      data=json.dumps(params).encode(), headers={'Content-Type': 'application/json'})
        with urlopen(req, timeout=45) as response:
            body = json.load(response)
        if not body.get('ok'):
            raise RuntimeError('Telegram API request failed: ' + method)
        return body['result']

    def send(self, chat, text, buttons=None):
        params = {'chat_id': chat, 'text': text}
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
            return 'Telegram Support', 'tg://user?id=' + raw
        return 'Telegram Support', 'tg://user?id=' + str(self.admin)

    def customer_buttons(self):
        return [('Track My Orders', 'mine'),
                {'text': 'Contact Support', 'url': self.support_contact()[1]},
                ('Main Menu', 'home')]

    def status_card(self, row):
        labels = {'draft': 'Waiting for your channel link', 'ready': 'Ready to confirm',
                  'awaiting_payment': 'Waiting for payment', 'queued': 'Preparing your order',
                  'processing': 'In progress', 'completed': 'Completed', 'cancelled': 'Cancelled'}
        status = labels.get(row['status'], row['status'])
        text = (f"Order ID: {row['id']}\nPackage: {row['product']}\n"
                f"Channel: {row['target'] or 'Not provided'}\nStatus: {status}\n")
        if row['status'] == 'queued':
            text += '\nYour order is confirmed. Our team will start delivery shortly.\nWe will send you an update when delivery starts and when it is complete.\n'
        elif row['status'] == 'processing':
            text += '\nOur team is working on your order. We will let you know when it is complete.\n'
        elif row['status'] == 'completed':
            text += '\nYour order is complete. Thank you for choosing Crowdio.\n'
        if self.demo:
            text += '\nDemo order: no payment was taken and no members will be delivered.\n'
        return text + '\nSupport: ' + self.support_contact()[0] + '\nPlease include your Order ID when contacting support.'

    def order(self, ident):
        return self.db.execute('SELECT * FROM orders WHERE id=?', (ident,)).fetchone()

    def home(self, user):
        self.send(user, ('DEMO — no money will be charged.\n' if self.demo else '') +
                  'Welcome to Crowdio.\n\n'
                  'Order Telegram channel members in three simple steps:\n'
                  '1. Choose a package.\n2. Send your public channel link.\n3. Review and confirm your order.\n\n'
                  'Track your order here and contact our team whenever you need help.',
                  [('New Order', 'new'), ('My Orders', 'mine'), ('Help & Terms', 'help'),
                   {'text': 'Contact Support', 'url': self.support_contact()[1]}])

    def paid(self, ident, user, currency, amount, charge):
        row = self.order(ident)
        if not row or row['user'] != user or currency != 'XTR' or amount != row['stars']:
            raise ValueError('Payment mismatch; manual reconciliation required')
        if row['charge'] == charge:
            return  # Telegram can redeliver successful_payment.
        if row['status'] != 'awaiting_payment':
            if not self.demo:
                self.api('refundStarPayment', user_id=user, telegram_payment_charge_id=charge)
            return
        with self.db:
            self.db.execute("UPDATE orders SET status='queued', charge=? WHERE id=?", (charge, ident))

    def notifications(self):
        # Retry unacknowledged admin notifications on the next poll. No customer funds are lost.
        rows = self.db.execute("SELECT * FROM orders WHERE status='queued' AND notified=0").fetchall()
        for row in rows:
            self.send(self.admin, f"Order {row['id']}\nUser: {row['user']}\n{row['product']}\n"
                      f"Target: {row['target']}\n{row['stars']} Stars\nStatus: queued",
                      [('Start processing', 'process:' + row['id']), ('Complete', 'complete:' + row['id'])])
            self.send(row['user'], self.status_card(row), self.customer_buttons())
            with self.db:
                self.db.execute('UPDATE orders SET notified=1 WHERE id=?', (row['id'],))

    def callback(self, q):
        user, data = q['from']['id'], q['data']
        self.api('answerCallbackQuery', callback_query_id=q['id'])
        if data == 'home':
            self.home(user)
        elif data == 'new':
            self.send(user, 'Choose your Telegram member package.\n\n'
                      'Select 500, 1,000 or 5,000 members. Prices are shown in Telegram Stars.', [(f"{p['title']} | {p['stars']:,} Stars", 'pick:' + str(i))
                                                   for i, p in enumerate(self.catalog)])
        elif data == 'mine':
            rows = self.db.execute('SELECT * FROM orders WHERE user=? ORDER BY created DESC LIMIT 10', (user,)).fetchall()
            if not rows:
                self.send(user, 'You have no orders yet. Choose a package to get started.', [('New Order', 'new')])
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
            self.send(user, f"Selected: {product['title']}\nPrice: {product['stars']:,} Stars\n\n" +
                      product['description'] + '\n\nSend your public Telegram channel link or username.\nExample: https://t.me/yourchannel or @yourchannel\n\nNever send your password or login code.')
        elif data.startswith('pay:'):
            row = self.order(data.split(':')[1])
            if not row or row['user'] != user or row['status'] != 'ready':
                return
            if self.demo:
                with self.db:
                    self.db.execute("UPDATE orders SET status='awaiting_payment' WHERE id=?", (row['id'],))
                self.paid(row['id'], user, 'XTR', row['stars'], 'demo-' + row['id'])
            else:
                # Mark first to prevent a duplicate callback issuing a second invoice.
                with self.db:
                    self.db.execute("UPDATE orders SET status='awaiting_payment' WHERE id=?", (row['id'],))
                self.api('sendInvoice', chat_id=user, title=row['product'][:32],
                         description=f"Order {row['id']} for {row['target']}. Manual fulfillment.",
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
            self.send(user, f'Order {ident}: {state}')
            self.send(row['user'], self.status_card(self.order(ident)), self.customer_buttons())

    def terms(self, user):
        self.send(user, (os.getenv('TERMS_TEXT') or
                  'Choose a package, send a public channel link and review the order before confirming.\n'
                  'Orders are handled by our team. Member counts do not guarantee views, clicks or engagement.\n'
                  'Contact support for delivery times and refill or refund terms before paying.') +
                  ('\n\nDemo mode: no payment or delivery.' if self.demo else '') +
                  '\n\nSupport: ' + self.support_contact()[0], self.customer_buttons())

    def support(self, user):
        self.send(user, 'Need help with an order or payment?\n\n'
                  'Contact our support team and include your Order ID.\n'
                  'You can find it under My Orders.\n\nSupport: ' + self.support_contact()[0],
                  self.customer_buttons())

    def handle(self, update):
        if 'pre_checkout_query' in update:
            q = update['pre_checkout_query']
            row = self.order(q['invoice_payload'])
            ok = bool(not self.demo and row and row['user'] == q['from']['id'] and
                      row['status'] == 'awaiting_payment' and q['currency'] == 'XTR' and
                      q['total_amount'] == row['stars'])
            args = {'pre_checkout_query_id': q['id'], 'ok': ok}
            if not ok:
                args['error_message'] = 'Order is invalid or already paid. Please contact support.'
            self.api('answerPreCheckoutQuery', **args)
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
            self.send(user, f"Review Your Order\n\nPackage: {row['product']}\nChannel: {link}\nTotal: {row['stars']:,} Stars\n\n"
                      'Please check the channel link carefully.\nBy confirming, you agree to our /terms.',
                      [('Confirm Demo Order' if self.demo else 'Confirm & Pay', 'pay:' + row['id']), ('Change Package', 'new')])

    def run(self, seconds=0):
        # Refuse to silently steal traffic from an existing webhook.
        if self.api('getWebhookInfo').get('url'):
            raise RuntimeError('Webhook exists. Remove it explicitly before using polling.')
        self.api('getMe')
        LOG.info('Bot connected; demo=%s; support configured=%s', self.demo,
                 bool((os.getenv('SUPPORT_CONTACT') or os.getenv('SUPPORT') or '').strip()))
        deadline = time.monotonic() + seconds if seconds else float('inf')
        saved = self.db.execute("SELECT value FROM settings WHERE key='offset'").fetchone()
        offset = saved[0] if saved else 0
        while time.monotonic() < deadline:
            try:
                updates = self.api('getUpdates', offset=offset, timeout=20,
                                   allowed_updates=['message', 'callback_query', 'pre_checkout_query'])
                for update in updates:
                    self.handle(update)
                    offset = update['update_id'] + 1
                    with self.db:
                        self.db.execute("INSERT OR REPLACE INTO settings VALUES('offset',?)", (offset,))
                self.notifications()
            except Exception as exc:
                # Never log URLs, tokens, payment payloads or customer data.
                LOG.error('Request or update failed (%s); will retry.', type(exc).__name__)
                time.sleep(3)


if __name__ == '__main__':
    logging.basicConfig(level=logging.INFO)
    demo = os.getenv('DEMO_MODE', '1') == '1'
    if not demo and (not os.getenv('SUPPORT_CONTACT') or not os.getenv('TERMS_TEXT')):
        raise SystemExit('Real payments require SUPPORT_CONTACT and TERMS_TEXT.')
    Bot(os.environ['BOT_TOKEN'], os.environ['ADMIN_ID'],
        os.getenv('DB_PATH', 'data/orders.sqlite3'), demo).run(int(os.getenv('RUN_SECONDS', '0')))
