-- This file is automatically generated using maintenance/generateSchemaSql.php.
-- Source: /var/www/wiki/mediawiki/extensions/GlobalUsage/sql/tables.json
-- Do not modify this file directly.
-- See https://www.mediawiki.org/wiki/Manual:Schema_changes
CREATE TABLE globalimagelinks (
  gil_wiki VARCHAR(32) NOT NULL,
  gil_page INT NOT NULL,
  gil_to VARCHAR(255) NOT NULL,
  gil_page_namespace_id INT NOT NULL,
  gil_page_namespace VARCHAR(255) DEFAULT NULL,
  gil_page_title VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY(gil_to, gil_wiki, gil_page)
);

CREATE INDEX globalimagelinks_wiki ON globalimagelinks (gil_wiki, gil_page);

CREATE INDEX globalimagelinks_wiki_nsid_title ON globalimagelinks (
  gil_wiki, gil_page_namespace_id,
  gil_page_title
);
