# WhatsApp & Viber messages: setup

There are two ways the shop can message customers. **Start with the free one.**

| | Free click-to-send (Viber + WhatsApp) | Automatic WhatsApp (Cloud API) |
|---|---|---|
| Cost | Free | Meta charges per message (see below) |
| Who presses "send" | You, in your own Viber / WhatsApp | Nobody — the server sends it |
| Setup | None | Meta Business account + approved templates (steps below) |
| Where | Order page, stylist requests, **Admin → Messages** | Admin → Messages → Automatic WhatsApp |
| Default | On | **Off** — nothing is ever sent until you switch it on |

There is no paid Viber option in the platform (Viber Business Messages need a contract with a Viber partner and a monthly fee).

---

## 1. Free click-to-send (nothing to set up)

- **Order page:** open an order → "Message the customer" → choose MK / SQ / EN → **WhatsApp** or **Viber**.
  The app opens with the message already written; you press send.
- **Admin → Messages → Send to a list:** pick a group (all customers, business accounts, bought in the last N days,
  haven't bought in N days, or paste numbers), pick a template, check the preview, then go down the list one person
  at a time: **Open WhatsApp / Open Viber / Skip / Mark sent**. Progress is kept on your phone, so you can stop and
  continue later. "Export CSV" downloads the list.
- **Admin → Messages → Templates:** change any message text in Macedonian, Albanian and English. Placeholders are
  filled in automatically: `{name}` first name, `{order}` order number, `{total}` order total, `{shop}` shop name,
  `{link}` website link, `{code}` a referral / discount code.

Tips
- Numbers like `078 286 332`, `+389 78 286 332` or `0038978...` all work; they are turned into `+389...`.
- Viber on a computer sometimes opens the chat without the text. The text is also copied, so just paste it.
  The **Copy** button is there for any other app too.
- Send group messages only to people who know your shop, and not too many in one go (for example 30–50 a day):
  WhatsApp and Viber may block a number that sends many identical messages to strangers.

---

## 2. Automatic WhatsApp (optional, Premium plan, costs money per message)

This is a **Premium** feature (feature switch `whatsapp_auto`). On other plans the settings page shows
"Upgrade to Premium" and no automatic message is sent. The free click-to-send tools work on every plan.

When it is on, the customer gets a WhatsApp message automatically when an order is placed, confirmed, shipped or
cancelled — only for the statuses you give a template to.

### What it costs (check before switching on)

- Meta charges **per delivered template message**. Order updates are "utility" messages; offers are "marketing"
  messages and cost more. Prices depend on the customer's country; for North Macedonia expect roughly a few
  euro cents per utility message and more for marketing. **Always check the current price list:**
  https://developers.facebook.com/docs/whatsapp/pricing
- **Free 24-hour window:** when a customer writes to you first, you can reply with normal (non-template) messages
  for free for 24 hours, and utility templates sent inside that window are free too.
- Meta asks for a payment card on the WhatsApp account before it delivers paid messages. Set a monthly limit there.
- The platform never sends while the switch is off, and on the staging (test) server it only messages the
  **test number** you enter, never real customers.

### Steps (about 30–60 minutes, free)

1. **Meta Business account** — go to https://business.facebook.com and create a business portfolio for the shop
   (or use the one that owns your Facebook / Instagram page).
2. **Developer app** — at https://developers.facebook.com → *My Apps* → *Create app* → type **Business** →
   add the **WhatsApp** product. Meta gives you a free test number and lets you message up to 5 verified numbers.
3. **Your real number** — WhatsApp → *API Setup* → *Add phone number*. Use a number that can receive an SMS or call
   for the code. **Tip:** use a separate number (e.g. a second SIM) for the API, so you can keep using your normal
   WhatsApp Business app for the free click-to-send messages.
4. **Phone number ID** — on the same *API Setup* page, copy the **Phone number ID** (a long number, not the phone
   number itself). The **WhatsApp Business Account ID** is shown there too (optional).
5. **Permanent access token** — the token on *API Setup* expires after 24 hours. For a permanent one:
   Business settings → *Users → System users* → *Add* (Admin) → *Add assets* → your app (full control) and your
   WhatsApp account → *Generate token* → choose the app, expiry **Never**, permissions
   `whatsapp_business_messaging` and `whatsapp_business_management`. Copy the token (it is shown only once).
6. **Message templates** — WhatsApp Manager → *Message templates* → *Create template*:
   - Category **Utility**, language **Macedonian** (and/or Albanian), a name with small letters and `_`,
     for example `order_confirmed`.
   - Body example: `Здраво {{1}}! Вашата нарачка #{{2}} од {{3}} е потврдена. Ви благодариме!`
   - The platform fills `{{1}}` = first name, `{{2}}` = order number, `{{3}}` = order total (e.g. `1.200 MKD`).
     A template may use 0, 1, 2 or 3 of them, in that order.
   - Approval usually takes minutes to a day. Create one per status you want: received, confirmed, shipped, cancelled.
7. **Connect it in the admin** — Admin → Messages → *Automatic WhatsApp*:
   - paste the **Phone number ID** and **access token**, press *Save* (the token is stored encrypted);
   - for each status, type the template **name**, its **language code** (`mk`, `sq`, `en_US` …) and how many
     `{{ }}` values it uses;
   - press **Send test**: with no template typed it sends Meta's ready-made `hello_world` (English) to the number you
     type — if it arrives, the connection works;
   - only then switch **Automatic messages** on. The last 20 automatic sends and any errors are listed on the page.
8. **Payment** — in WhatsApp Manager → *Payment settings*, add a card and set a spending limit.

### Switching it off

Switch **Automatic messages** off on the same page (takes effect immediately), or press *Remove token*.

---

## Technical notes (for a developer)

- Code: `app/Services/Messaging/*`, `app/Http/Controllers/Api/V1/Admin/AdminMessagingController.php`,
  listeners `SendWhatsAppOrderReceived` / `SendWhatsAppOrderStatus` (queued, `tries = 1` so a retry can't bill twice).
- API (admin only, feature switch `messaging`): `GET|PUT /api/v1/admin/messaging/templates`,
  `POST /messaging/audience`, `GET|PUT /messaging/whatsapp`, `POST /messaging/whatsapp/test`.
- Settings live in the `settings` table: `messaging.templates`, `messaging.shop`, `messaging.link`, `whatsapp.*`
  (`whatsapp.access_token` is encrypted with the app key — changing `APP_KEY` means pasting the token again).
- No new database tables or migrations.
- Calls `https://graph.facebook.com/{version}/{phone-number-id}/messages`; version defaults to `v23.0` and can be
  changed on the settings page when Meta retires it.
