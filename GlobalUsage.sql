CREATE TABLE /*$wgDBprefix*/globalimagelinks (
	-- Interwiki prefix
	gil_wiki varchar(32) not null,
	-- page_id on the local wiki
	gil_page int unsigned not null,
	-- Namespace, since the foreign namespaces may not match the local ones
	gil_page_namespace varchar(255) not null,
	-- Page title
	gil_page_title varchar(255) not null,
	-- Image name
	gil_to varchar(255) not null,

	
	-- Note: You might want to shorten the gil_wiki part of the indices.
	-- If the domain format is used, only the "en.wikip" part is needed for an
	-- unique lookup
	
	PRIMARY KEY (gil_to, gil_wiki, gil_page), 
	INDEX (gil_wiki, gil_page)
) /*$wgDBTableOptions*/;
