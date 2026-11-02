import tempfile
import unittest
from unittest.mock import patch
from bot import Bot, target
import json
import sqlite3
from state_store import GitHubState

class Orders(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.bot = Bot('fake', 99, self.tmp.name + '/orders.db')
        self.sent = []
        self.bot.api = lambda method, **kwargs: self.sent.append((method, kwargs)) or {}
        self.bot.db.execute("INSERT INTO orders(id,user,product,stars,target,status,created) VALUES('x',1,'Package',385,'@hello','awaiting_payment',1)")
        self.bot.db.commit()
    def tearDown(self):
        self.bot.db.close()
        self.tmp.cleanup()
    def test_payment_validation(self):
        for user, currency, price in [(2,'XTR',385),(1,'USD',385),(1,'XTR',1)]:
            with self.assertRaises(ValueError):
                self.bot.paid('x',user,currency,price,'charge')
        self.assertEqual(self.bot.order('x')['status'],'awaiting_payment')
    def test_duplicate_payment(self):
        for _ in range(2):
            self.bot.paid('x',1,'XTR',385,'charge')
        self.assertEqual(self.bot.order('x')['status'],'queued')
        self.bot.notifications()
        count = len(self.sent)
        self.bot.notifications()
        self.assertEqual(count,len(self.sent))
    def test_admin_permissions(self):
        self.bot.paid('x',1,'XTR',385,'charge')
        self.bot.callback({'id':'q','from':{'id':1},'data':'complete:x'})
        self.assertEqual(self.bot.order('x')['status'],'queued')
        self.bot.callback({'id':'q','from':{'id':99},'data':'complete:x'})
        self.assertEqual(self.bot.order('x')['status'],'completed')
    def test_unpaid_cannot_complete(self):
        self.bot.callback({'id':'q','from':{'id':99},'data':'complete:x'})
        self.assertEqual(self.bot.order('x')['status'],'awaiting_payment')
    def test_precheckout(self):
        self.bot.demo = False
        self.bot.handle({'pre_checkout_query':{'id':'q','invoice_payload':'x','from':{'id':2},'currency':'XTR','total_amount':385}})
        self.assertFalse(self.sent[-1][1]['ok'])
    def test_target(self):
        self.assertEqual(target('https://t.me/hello_world'),'@hello_world')
        self.assertIsNone(target('https://example.com/hello_world'))
        self.assertIsNone(target('https://t.me/+private'))

    def test_three_packages_and_prices(self):
        self.bot.callback({'id':'q','from':{'id':1},'data':'new'})
        rows = self.sent[-1][1]['reply_markup']['inline_keyboard']
        self.assertEqual(len([r for r in rows if r[0].get('callback_data', '').startswith('pick:')]), 3)
        self.assertEqual([p['stars'] for p in self.bot.catalog], [193,385,1925])
        self.assertEqual([p['title'] for p in self.bot.catalog],
                         ['500 Telegram members','1,000 Telegram members','5,000 Telegram members'])

    def test_support_username_and_numeric_link(self):
        with patch.dict('os.environ', {'SUPPORT_CONTACT':' @crowdio_help ','SUPPORT':''}):
            self.assertEqual(self.bot.support_contact(), ('@crowdio_help','https://t.me/crowdio_help'))
            self.bot.support(1)
            rows = self.sent[-1][1]['reply_markup']['inline_keyboard']
            self.assertEqual(rows[0][0]['url'], 'https://t.me/crowdio_help')
        with patch.dict('os.environ', {'SUPPORT_CONTACT':'','SUPPORT':'123456789'}):
            self.assertEqual(self.bot.support_contact()[1], 'tg://user?id=123456789')

    def test_confirmation_and_progress_include_support(self):
        self.bot.paid('x',1,'XTR',385,'charge')
        self.bot.notifications()
        message = [params for method, params in self.sent if method == 'sendMessage' and params['chat_id'] == 1][-1]
        self.assertIn('Order ID: <code>x</code>', message['text'])
        self.assertIn('Awaiting delivery', message['text'])
        self.assertIn('no members will be delivered', message['text'])
        self.assertEqual(message['reply_markup']['inline_keyboard'][0][0]['text'], '💬 Contact Support')
        self.bot.callback({'id':'q','from':{'id':99},'data':'process:x'})
        self.assertIn('<b>In progress</b>', self.sent[-1][1]['text'])

    def test_new_order_review_keeps_selected_price(self):
        self.bot.callback({'id':'q','from':{'id':1},'data':'pick:2'})
        self.bot.handle({'message':{'chat':{'type':'private'},'from':{'id':1},'text':'@hello_world'}})
        self.assertIn('5,000 Telegram members', self.sent[-1][1]['text'])
        self.assertIn('1,925 Stars', self.sent[-1][1]['text'])

    def test_live_invoice_does_not_mark_order_paid(self):
        self.bot.demo = False
        self.bot.db.execute("UPDATE orders SET status='ready' WHERE id='x'")
        self.bot.db.commit()
        self.bot.callback({'id':'q','from':{'id':1},'data':'pay:x'})
        method, invoice = self.sent[-1]
        self.assertEqual(method, 'sendInvoice')
        self.assertEqual(invoice['currency'], 'XTR')
        self.assertEqual(invoice['prices'], [{'label':'Package','amount':385}])
        self.assertEqual(self.bot.order('x')['status'], 'awaiting_payment')
        self.assertIsNone(self.bot.order('x')['charge'])
        self.bot.handle({'message':{'chat':{'type':'private'},'from':{'id':1},'successful_payment':{
            'invoice_payload':'x','currency':'XTR','total_amount':385,'telegram_payment_charge_id':'real-like-charge'}}})
        self.assertEqual(self.bot.order('x')['status'], 'queued')
        self.assertNotIn('Demo', self.bot.status_card(self.bot.order('x')))

    def test_live_precheckout_and_shutdown_window(self):
        self.bot.demo = False
        query = {'pre_checkout_query':{'id':'q','invoice_payload':'x','from':{'id':1},'currency':'XTR','total_amount':385}}
        self.bot.handle(query)
        self.assertTrue(self.sent[-1][1]['ok'])
        self.bot.accept_until = 0
        self.bot.handle(query)
        self.assertFalse(self.sent[-1][1]['ok'])

    def test_customer_receipt_precedes_admin_notification(self):
        self.bot.demo = False
        self.bot.paid('x',1,'XTR',385,'real-like-charge')
        self.bot.notifications()
        messages = [params for method, params in self.sent if method == 'sendMessage']
        self.assertEqual(messages[0]['chat_id'], 1)
        self.assertIn('Payment received', messages[0]['text'])
        self.assertIn('Awaiting delivery', messages[0]['text'])
        self.assertIn('Contact Support', messages[0]['reply_markup']['inline_keyboard'][0][0]['text'])

    def test_edit_channel_checks_owner(self):
        self.bot.db.execute("UPDATE orders SET status='ready' WHERE id='x'")
        self.bot.db.commit()
        self.bot.callback({'id':'q','from':{'id':2},'data':'edit:x'})
        self.assertEqual(self.bot.order('x')['status'], 'ready')
        self.bot.callback({'id':'q','from':{'id':1},'data':'edit:x'})
        self.assertEqual(self.bot.order('x')['status'], 'draft')

    def test_encrypted_payment_state_roundtrip(self):
        store = GitHubState('fake-token', 'example/repo', 'fake-github-token')
        published = []
        def write(method='GET', data=None):
            published.append(data)
            return {'content':{'sha':'new-sha'}}
        store.request = write
        self.bot.state_store = store
        self.bot.paid('x',1,'XTR',385,'saved-charge')
        self.assertNotIn('saved-charge', json.dumps(published))
        clone = GitHubState('fake-token', 'example/repo', 'fake-github-token')
        clone.request = lambda: {'sha':'new-sha','content':published[-1]['content']}
        restored = self.tmp.name + '/restored.sqlite3'
        clone.restore(restored)
        db = sqlite3.connect(restored)
        self.assertEqual(db.execute("SELECT charge,status FROM orders WHERE id='x'").fetchone(), ('saved-charge','queued'))
        db.close()

    def test_failed_checkpoint_retries_same_payment(self):
        self.bot.state_store = type('BrokenStore', (), {'save':lambda _, db: (_ for _ in ()).throw(RuntimeError('offline'))})()
        with self.assertRaises(RuntimeError):
            self.bot.paid('x',1,'XTR',385,'saved-charge')
        self.bot.state_store = None
        self.bot.paid('x',1,'XTR',385,'saved-charge')
        self.assertEqual(self.bot.order('x')['status'], 'queued')

if __name__ == '__main__':
    unittest.main()
