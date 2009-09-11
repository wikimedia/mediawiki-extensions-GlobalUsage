When using a shared image repository, it is impossible to see within MediaWiki
whether a file is used on one of the slave wikis. On Wikimedia this is handled
by the CheckUsage tool on the toolserver, but it is merely a hack of function 
that should be built in.

GlobalUsage creates a new table globalimagelinks, which is basically the same
as imagelinks, but includes the usage of all images on all associated wikis,
including local images. The field il_from has been replaced by gil_wiki,
gil_page, gil_page_namespace and gil_page_title which contain respectively the
interwiki prefix, page id and page namespace and title. Since the foreign wiki
may use different namespaces, the namespace name needs to be included in the 
link as well.


