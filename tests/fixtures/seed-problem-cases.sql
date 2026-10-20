-- ThreadSafe test fixture: problem cases added on top of a fresh PrestaShop install.
-- Placeholders (replaced by tests/stack/seed.sh):
--   {{prefix}}          table prefix
--   {{domain}}          shop domain as stored in PS_SHOP_DOMAIN, e.g. localhost:8190
--   {{domain_encoded}}  the same, percent-encoded, e.g. localhost%3A8190

-- 1. CMS content with the domain in the notations real shops contain.
UPDATE {{prefix}}cms_lang SET content = CONCAT(
    IFNULL(content, ''),
    '<p>Seed: ',
    '<a href="http://www.{{domain}}/content/seed">www variant</a> ',
    '<a href="https://{{domain}}/en/content/1-delivery">https</a> ',
    '<img src="//{{domain}}/img/seed.png"> ',
    'Bare mention: {{domain}} ',
    'Foreign: <a href="http://other.test/page">other.test</a>, <a href="mailto:info@other.test">info@other.test</a> ',
    'Encoded: <a href="/redirect?back=http%3A%2F%2F{{domain_encoded}}%2Fcart">back</a> ',
    'JSON-escaped: http:\\/\\/{{domain}}\\/escaped</p>'
);

-- 2. A cache table entry with the domain (must be emptied by the migration).
INSERT INTO {{prefix}}smarty_cache (id_smarty_cache, name, cache_id, content)
VALUES (SHA1('threadsafe-seed'), SHA1('threadsafe-seed'), 'threadsafe-seed',
        'cached <a href="http://{{domain}}/cached">link</a>');

-- 3. A big log table (200,000 rows) that must be skipped by the content replacement.
INSERT INTO {{prefix}}connections_source (id_connections, http_referer, request_uri, keywords, date_add)
SELECT 1, CONCAT('http://{{domain}}/search?q=', seq), CONCAT('/page/', seq), '', NOW()
FROM seq_1_to_200000;

-- 4. Marker so that seeding runs only once.
INSERT INTO {{prefix}}configuration (name, value, date_add, date_upd)
VALUES ('THREADSAFE_SEEDED', '1', NOW(), NOW());
