-- ThreadSafe test fixture: the live connections a real shop carries (mail, PayPal, PrestaShop Account, Facebook).
-- All values are invented. Placeholder (replaced by tests/stack/seed.sh): {{prefix}} table prefix.
-- The dev mode must neutralise every one of them.

-- 1. Mail through a "real" SMTP account.
UPDATE {{prefix}}configuration SET value = '2' WHERE name = 'PS_MAIL_METHOD';
UPDATE {{prefix}}configuration SET value = 'smtp.live-shop.invalid' WHERE name = 'PS_MAIL_SERVER';
UPDATE {{prefix}}configuration SET value = '587' WHERE name = 'PS_MAIL_SMTP_PORT';
UPDATE {{prefix}}configuration SET value = 'tls' WHERE name = 'PS_MAIL_SMTP_ENCRYPTION';
UPDATE {{prefix}}configuration SET value = 'shop@live-shop.invalid' WHERE name = 'PS_MAIL_USER';
UPDATE {{prefix}}configuration SET value = 'seed-smtp-password-1234' WHERE name = 'PS_MAIL_PASSWD';

-- 2. Live PayPal.
INSERT INTO {{prefix}}configuration (name, value, date_add, date_upd) VALUES
    ('PAYPAL_SANDBOX', '0', NOW(), NOW()),
    ('PAYPAL_LIVE_CLIENTID', 'seed-paypal-live-client-id', NOW(), NOW()),
    ('PAYPAL_LIVE_SECRET', 'seed-paypal-live-secret-123', NOW(), NOW()),
    ('PAYPAL_WEBHOOK_ID', 'WH-SEED-LIVE-0001', NOW(), NOW()),
    ('PAYPAL_ENABLE_WEBHOOK', '1', NOW(), NOW());
CREATE TABLE {{prefix}}paypal_webhook (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    payload TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO {{prefix}}paypal_webhook (payload) VALUES ('{"event":"seed"}'), ('{"event":"seed2"}');

-- 3. PrestaShop Account (identifies the shop in PrestaShop's cloud) and Facebook.
INSERT INTO {{prefix}}configuration (name, value, date_add, date_upd) VALUES
    ('PS_ACCOUNTS_RSA_PRIVATE_KEY', 'seed-rsa-private-key-material', NOW(), NOW()),
    ('PS_ACCOUNTS_FIREBASE_REFRESH_TOKEN', 'seed-firebase-refresh-token', NOW(), NOW()),
    ('PS_PSX_FIREBASE_ID_TOKEN', 'seed-psx-firebase-id-token', NOW(), NOW()),
    ('PS_FACEBOOK_ACCESS_TOKEN', 'seed-facebook-access-token', NOW(), NOW());
INSERT INTO {{prefix}}eventbus_incremental_sync (type, action, id_object, id_shop, lang_iso, created_at)
VALUES ('products', 'upsert', '4', 1, 'en', NOW());

-- 4. A credential no dev rule knows: the migration has to point it out.
INSERT INTO {{prefix}}configuration (name, value, date_add, date_upd) VALUES
    ('THREADSAFE_FIXTURE_API_KEY', 'seed-unknown-service-key', NOW(), NOW());

-- 5. Marker so that this part runs only once.
INSERT INTO {{prefix}}configuration (name, value, date_add, date_upd) VALUES ('THREADSAFE_SEEDED_LIVE_LINKS', '1', NOW(), NOW());
