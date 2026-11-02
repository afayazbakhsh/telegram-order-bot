import tempfile
import unittest
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

if __name__ == '__main__':
    unittest.main()
