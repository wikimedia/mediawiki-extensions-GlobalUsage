CREATE TABLE /*$wgDBprefix*/globalimagelinks (
	-- Wiki id
	gil_wiki varchar(32) not null,
	-- page_id on the local wiki
	gil_page int unsigned not null,
	-- Namespace, since the foreign namespaces may not match the local ones
	gil_page_namespace varchar(255) not null,
	-- Page title
	gil_page_title varchar(255) binary not null,
	-- Image name
	gil_to varchar(255) binary not null,
	
	PRIMARY KEY (gil_to, gil_wiki, gil_page)
) /*$wgDBTableOptions*/;

CREATE INDEX globalimagelinks_wiki ON globalimagelinks(gil_wiki, gil_page);
