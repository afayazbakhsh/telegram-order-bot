import tempfile
import unittest
from unittest.mock import patch
from bot import Bot, target

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
        self.assertEqual(len(rows), 3)
        self.assertEqual([p['stars'] for p in self.bot.catalog], [193,385,1925])
        self.assertEqual([p['title'] for p in self.bot.catalog],
                         ['500 Telegram members','1,000 Telegram members','5,000 Telegram members'])

    def test_support_username_and_numeric_link(self):
        with patch.dict('os.environ', {'SUPPORT_CONTACT':' @crowdio_help ','SUPPORT':''}):
            self.assertEqual(self.bot.support_contact(), ('@crowdio_help','https://t.me/crowdio_help'))
            self.bot.support(1)
            rows = self.sent[-1][1]['reply_markup']['inline_keyboard']
            self.assertEqual(rows[1][0]['url'], 'https://t.me/crowdio_help')
        with patch.dict('os.environ', {'SUPPORT_CONTACT':'','SUPPORT':'123456789'}):
            self.assertEqual(self.bot.support_contact()[1], 'tg://user?id=123456789')

    def test_confirmation_and_progress_include_support(self):
        self.bot.paid('x',1,'XTR',385,'charge')
        self.bot.notifications()
        message = self.sent[-1][1]
        self.assertIn('Order ID: x', message['text'])
        self.assertIn('Preparing your order', message['text'])
        self.assertIn('no members will be delivered', message['text'])
        self.assertEqual(message['reply_markup']['inline_keyboard'][1][0]['text'], 'Contact Support')
        self.bot.callback({'id':'q','from':{'id':99},'data':'process:x'})
        self.assertIn('Status: In progress', self.sent[-1][1]['text'])

    def test_new_order_review_keeps_selected_price(self):
        self.bot.callback({'id':'q','from':{'id':1},'data':'pick:2'})
        self.bot.handle({'message':{'chat':{'type':'private'},'from':{'id':1},'text':'@hello_world'}})
        self.assertIn('5,000 Telegram members', self.sent[-1][1]['text'])
        self.assertIn('1,925 Stars', self.sent[-1][1]['text'])

if __name__ == '__main__':
    unittest.main()
