<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Models\OrderInfo;
use App\Services\Messaging\PhoneNumber;
use App\Services\Messaging\WhatsAppCloudClient;
use App\Services\Messaging\WhatsAppSender;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** No database: phone numbers, the Meta API call and the staging safety rule. */
class WhatsAppMessagingTest extends TestCase
{
    #[Test]
    public function macedonian_numbers_become_international_digits(): void
    {
        $this->assertSame('38978286332', PhoneNumber::normalize('078 286 332'));
        $this->assertSame('38978286332', PhoneNumber::normalize('+389 78 286 332'));
        $this->assertSame('38978286332', PhoneNumber::normalize('0038978286332'));
        $this->assertSame('38978286332', PhoneNumber::normalize('78286332'));
        $this->assertSame('38923333003', PhoneNumber::normalize('02 333 3003'));
        $this->assertSame('4915112345678', PhoneNumber::normalize('+49 151 12345678'));
        $this->assertNull(PhoneNumber::normalize('12'));
        $this->assertNull(PhoneNumber::normalize(null));
    }

    #[Test]
    public function sends_an_approved_template_with_the_order_details(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.123']]])]);

        $result = (new WhatsAppCloudClient)->sendTemplate('1055', 'secret-token', 'v23.0', '38978286332', 'order_shipped', 'mk', ['Ana', '42', '1.200 MKD']);

        $this->assertSame(['ok' => true, 'id' => 'wamid.123', 'error' => null], $result);
        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://graph.facebook.com/v23.0/1055/messages'
                && $request->hasHeader('Authorization', 'Bearer secret-token')
                && $request['to'] === '38978286332'
                && $request['template']['name'] === 'order_shipped'
                && $request['template']['language']['code'] === 'mk'
                && $request['template']['components'][0]['parameters'][2]['text'] === '1.200 MKD';
        });
    }

    #[Test]
    public function reports_meta_errors_in_plain_words(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Template name does not exist in the translation']], 400)]);

        $result = (new WhatsAppCloudClient)->sendTemplate('1055', 't', 'v23.0', '38978286332', 'nope', 'mk');

        $this->assertFalse($result['ok']);
        $this->assertSame('Template name does not exist in the translation', $result['error']);
    }

    #[Test]
    public function staging_only_messages_the_test_number(): void
    {
        $this->assertSame('38978286332', WhatsAppSender::deliverTo('38978286332', true, '38970000000'));
        $this->assertSame('38970000000', WhatsAppSender::deliverTo('38978286332', false, '38970000000'));
        $this->assertNull(WhatsAppSender::deliverTo('38978286332', false, null));
    }

    #[Test]
    public function fills_template_values_from_the_order(): void
    {
        $order = new Order(['total' => 1234.4]);
        $order->id = 42;
        $order->setRelation('info', new OrderInfo(['first_name' => 'Arta']));
        $order->setRelation('user', null);

        $this->assertSame(['Arta', '42', '1.234 MKD'], WhatsAppSender::orderParams($order, 3));
        $this->assertSame(['Arta'], WhatsAppSender::orderParams($order, 1));
        $this->assertSame([], WhatsAppSender::orderParams($order, 0));
    }
}
