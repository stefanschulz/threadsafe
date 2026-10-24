-- The problem cases for the Joomla tests (tests/stack/seed-joomla.sh loads this into a freshly installed Joomla 5 or 6).
-- {{prefix}} is the table prefix of the site, {{domain}} its domain with the port (localhost:8195 / localhost:8196), {{domain_encoded}} the
-- same with the colon URL-encoded. JSON is written the way Joomla writes it: slashes escaped.
--
-- What must be replaced when the site moves: the domain in every form below (plain, https, www-less, JSON with escaped slashes,
-- URL-encoded). What must stay: a foreign domain, a sub-domain of the site's own host, an e-mail address, the bare host name in text.

SET NAMES utf8mb4;

-- --- articles: HTML with absolute URLs, images and links; a second one for the search-engine friendly address; umlauts and a 4-byte character
INSERT INTO `{{prefix}}content` (`id`, `asset_id`, `title`, `alias`, `introtext`, `fulltext`, `state`, `catid`, `created`, `created_by`, `created_by_alias`, `modified`, `modified_by`, `publish_up`, `images`, `urls`, `attribs`, `version`, `ordering`, `metakey`, `metadesc`, `access`, `hits`, `metadata`, `featured`, `language`, `note`) VALUES
(901, 0, 'Welcome to the seeded site', 'welcome', CONCAT(
  '<p>Visit <a href="http://{{domain}}/index.php/about">about us</a>, <a href="https://{{domain}}/index.php/component/content/article/about-us?catid=2">the same page</a> or <a href="http://{{domain}}/">home</a>.</p>',
  '<p><img src="http://{{domain}}/images/seed/photo.bin" alt="a photo" width="10" height="10"> and <img src="/images/seed/photo.bin" alt="relative"></p>',
  '<p>Foreign: <a href="https://foreign.example/page">foreign.example</a>. Sub-domain: <a href="http://shop.{{domain}}/x">shop</a>. Mail: <a href="mailto:editor@localhost">editor@localhost</a>. The bare host {{domain}} stays in this sentence.</p>',
  '<p>Share: <a href="https://social.example/share?url=http%3A%2F%2F{{domain_encoded}}%2Findex.php%2Fabout">share</a></p>'),
 '<p>Grüße aus dem Fixture: Straße, Äpfel, 日本語 and a world 🌍.</p>', 1, 2, '2026-01-02 03:04:05', 240, '', '2026-01-02 03:04:05', 240, '2026-01-02 03:04:05',
 '{"image_intro":"images\\/seed\\/photo.bin","image_intro_alt":"","float_intro":"","image_intro_caption":"","image_fulltext":"","image_fulltext_alt":"","float_fulltext":"","image_fulltext_caption":""}',
 '{"urla":"https:\\/\\/{{domain}}\\/index.php\\/about","urlatext":"About","targeta":"","urlb":"https:\\/\\/foreign.example\\/page","urlbtext":"Foreign","targetb":"","urlc":false,"urlctext":"","targetc":""}',
 '{"article_layout":"","show_title":"","link_titles":"","show_tags":"","show_intro":"","info_block_position":"","info_block_show_title":"","show_category":"","link_category":"","show_parent_category":"","link_parent_category":"","show_associations":"","show_author":"","link_author":"","show_create_date":"","show_modify_date":"","show_publish_date":"","show_item_navigation":"","show_hits":"","show_noauth":"","urls_position":"","alternative_readmore":"","article_page_title":"","show_publishing_options":"","show_article_options":"","show_urls_images_backend":"","show_urls_images_frontend":""}',
 1, 0, '', 'The seeded welcome article', 1, 5, '{"robots":"","author":"","rights":""}', 1, '*', ''),
(902, 0, 'About us', 'about-us', '<p>About the seeded site. <a href="http://{{domain}}/index.php/welcome">back</a></p>', '', 1, 2, '2026-01-02 03:04:05', 240, '', '2026-01-02 03:04:05', 240, '2026-01-02 03:04:05',
 '{}', '{}', '{}', 1, 0, '', '', 1, 3, '{}', 0, '*', '');

-- --- menu items: an external URL with the domain, and an article; the nested set of the main menu grows by two items
UPDATE `{{prefix}}menu` SET `rgt` = 47 WHERE `id` = 1;
INSERT INTO `{{prefix}}menu` (`id`, `menutype`, `title`, `alias`, `note`, `path`, `link`, `type`, `published`, `parent_id`, `level`, `component_id`, `browserNav`, `access`, `img`, `template_style_id`, `params`, `lft`, `rgt`, `home`, `language`, `client_id`) VALUES
(901, 'mainmenu', 'About', 'about', '', 'about', 'index.php?option=com_content&view=article&id=902&catid=2', 'component', 1, 1, 1, (SELECT `extension_id` FROM `{{prefix}}extensions` WHERE `element` = 'com_content' LIMIT 1), 0, 1, '', 0, '{"show_title":"","link_titles":"","show_intro":"","info_block_position":"","menu-anchor_title":"","menu-anchor_css":"","menu_image":"","menu_image_css":"","menu_text":1,"menu_show":1}', 43, 44, 0, '*', 0),
(902, 'mainmenu', 'Our own site', 'our-own-site', '', 'our-own-site', 'https://{{domain}}/index.php/welcome', 'url', 1, 1, 1, 0, 0, 1, '', 0, '{"menu-anchor_title":"","menu-anchor_css":"","menu_image":"","menu_image_css":"","menu_text":1,"menu_show":1}', 45, 46, 0, '*', 0);

-- --- a custom module with the domain in its content and in its JSON parameters, shown on every page
INSERT INTO `{{prefix}}modules` (`id`, `asset_id`, `title`, `note`, `content`, `ordering`, `position`, `published`, `module`, `access`, `showtitle`, `params`, `client_id`, `language`) VALUES
(901, 0, 'Seeded HTML', '', '<p>Find us at <a href="http://{{domain}}/">{{domain}}</a>. Share on <a href="https://social.example/share?url=http%3A%2F%2F{{domain_encoded}}%2F">social</a>.</p>', 1, 'sidebar-right', 1, 'mod_custom', 1, 1,
 '{"prepare_content":"1","backgroundimage":"","layout":"_:default","moduleclass_sfx":"","cache":"1","cache_time":"900","cachemode":"static","style":"0","link":"https:\\/\\/{{domain}}\\/index.php\\/about"}', 0, '*');
INSERT INTO `{{prefix}}modules_menu` (`moduleid`, `menuid`) VALUES (901, 0);

-- --- the template style (parameters are JSON), a redirect of the site's own addresses
UPDATE `{{prefix}}template_styles` SET `params` = REPLACE(REPLACE(`params`, '"logoFile":""', '"logoFile":"images\\/seed\\/logo.png"'), '"siteDescription":""', '"siteDescription":"Seeded at https:\\/\\/{{domain}}\\/"') WHERE `template` = 'cassiopeia' AND `client_id` = 0;
INSERT INTO `{{prefix}}redirect_links` (`old_url`, `new_url`, `referer`, `comment`, `hits`, `published`, `created_date`, `modified_date`, `header`) VALUES
('http://{{domain}}/old-page', 'http://{{domain}}/index.php/about', '', 'seeded redirect', 2, 1, '2026-01-02 03:04:05', '2026-01-02 03:04:05', 301);

-- --- a third-party extension (not enabled, there are no files for it): its manifest says who made it, its parameters hold the domain
INSERT INTO `{{prefix}}extensions` (`name`, `type`, `element`, `folder`, `client_id`, `enabled`, `access`, `protected`, `locked`, `manifest_cache`, `params`, `custom_data`, `ordering`, `state`) VALUES
('plg_system_threadsafe_fixture', 'plugin', 'threadsafe_fixture', 'system', 0, 0, 1, 0, 0,
 '{"name":"plg_system_threadsafe_fixture","type":"plugin","creationDate":"2026-01-01","author":"ThreadSafe test","copyright":"","authorEmail":"","authorUrl":"","version":"1.2.3","description":"A fixture that is not installed for real","group":"","filename":"threadsafe_fixture"}',
 '{"endpoint":"https:\\/\\/{{domain}}\\/api\\/","callback":"http%3A%2F%2F{{domain_encoded}}%2Fcb"}', '', 0, 0);

-- --- what a backup keeps empty or keeps whole: sessions, the audit trail, a remember-me token
INSERT INTO `{{prefix}}session` (`session_id`, `client_id`, `guest`, `time`, `data`, `userid`, `username`) VALUES
('seedsession00000000000000000001', 0, 1, UNIX_TIMESTAMP(), 'joomla|s:30:"http://{{domain}}/index.php";', 0, ''),
('seedsession00000000000000000002', 0, 1, UNIX_TIMESTAMP(), 'joomla|s:5:"empty";', 0, ''),
('seedsession00000000000000000003', 1, 0, UNIX_TIMESTAMP(), 'joomla|s:5:"admin";', 240, 'seedadmin');
INSERT INTO `{{prefix}}action_logs` (`message_language_key`, `message`, `log_date`, `extension`, `user_id`, `item_id`, `ip_address`) VALUES
('PLG_ACTIONLOG_JOOMLA_USER_LOGGED_IN', '{"action":"login","id":240,"title":"seedadmin","link":"http:\\/\\/{{domain}}\\/administrator\\/index.php?option=com_users"}', '2026-01-02 03:04:05', 'com_users', 240, 240, '127.0.0.1');
INSERT INTO `{{prefix}}user_keys` (`user_id`, `token`, `series`, `time`, `uastring`) VALUES ('seedadmin', 'seedtoken', 'seedseries', '1767323045', 'seed');
