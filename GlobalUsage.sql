CREATE TABLE /*$wgDBprefix*/globalimagelinks (
	-- Interwiki prefix
	gil_wiki varchar(32) not null,
	-- page_id on the local wiki
	gil_page int unsigned not null,
	-- Full pagename including namespace, since the foreign namespaces may not
	-- match the local ones
	gil_pagename varchar(511) not null,
	-- Image name
	gil_to varchar(255) not null,
	
	PRIMARY KEY (gil_wiki, gil_page),
	INDEX (gil_to)
) /*$wgDBTableOptions*/;