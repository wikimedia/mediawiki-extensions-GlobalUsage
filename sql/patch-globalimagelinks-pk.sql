-- Convert unique index to primary key
-- See T243987
ALTER TABLE /*_*/globalimagelinks
DROP INDEX /*i*/globalimagelinks_to_wiki_page,
ADD PRIMARY KEY (gil_to, gil_wiki, gil_page);