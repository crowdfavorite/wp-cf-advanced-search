## CF Advanced Search

The Advanced Search Plugin provides more in depth search abilities to WordPress. The Advanced Search Admin screen allows admin users to maintain and modify the search index table.

### Rebuild the Search Index

The search index can be rebuilt manually. Though with normal usage it should not be necessary, there are situations where it might be needed:

- On initial install of the plugin
- Upon modification of the post-exclude rules
- If content has been imported in to WordPress
- If a plugin that alters the indexing of posts has been installed or modified

To rebuild the search index:

- Click on "Advanced Search" under the "Setting" section of the WordPress Admin Sidebar
- Click on "Rebuild Index"
	- To manage high volumes of conent the search index will be rebuilt via batched ajax calls to WordPress
	- **Do not navigate away from the page while the index is being rebuilt**
	- If for some reason the search index is not allowed to complete simply return to the page and click "Rebuild Index" again
	

### Excluding Posts From the Search Index

Posts can be excluded from the search index by category. If a post's **ONLY** category is in the list of categories to be filtered it will not be entered in to the search index. If the post is in multiple categories and one of those is valid to be entered in to the search index it will be indexed.

To exclude a category of posts from being indexed:

- Click on "Advanced Search" under the "Settings" section of the WordPress Admin Sidebar
- Select the categories to be excluded from the list of categories in the Search Excludes section
- Click "Save Excludes" to save the changes
- **After the changes have been saved the search index must be rebuilt using the instructions above**