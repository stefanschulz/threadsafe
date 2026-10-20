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
FROM (
    -- the numbers 1 to 200,000 without a sequence engine, so that the seed runs on MariaDB and MySQL alike
    SELECT (a.n * 100000 + b.n * 10000 + c.n * 1000 + d.n * 100 + e.n * 10 + f.n + 1) AS seq
    FROM (SELECT 0 AS n UNION ALL SELECT 1) a,
         (SELECT 0 AS n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) b,
         (SELECT 0 AS n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) c,
         (SELECT 0 AS n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) d,
         (SELECT 0 AS n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) e,
         (SELECT 0 AS n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) f
) numbers;

-- 4. Marker so that seeding runs only once.
INSERT INTO {{prefix}}configuration (name, value, date_add, date_upd)
VALUES ('THREADSAFE_SEEDED', '1', NOW(), NOW());
