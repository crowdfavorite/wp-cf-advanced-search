## CF Advanced Search

Tested on: WordPress 2.6 - 2.8

CF Advanced Search is a plugin that overrides the default WordPress search routine to give your WordPress site advanced search results and provide your users with greater control over searching your content. When Installed on WordPress MU the plugin can be configured to index all blogs to allow for multi-blog search results. This plugin does not create a new search results page but rather opts to use the default WordPress search results layout and pagination. The default WordPress method of performing a search, `?s=search_term`, is still used. This enables the plugin to drop in to any existing theme that has used the default WordPress in its design.


---

### Standard WordPress Installation

If your site uses the standard WordPress theme structure:

- add the cf-advanced-search plugin to your plugins directory (`wp-content/plugins`)
- copy `Templates/Std-WordPress/advanced-search.php` to your theme directory (`wp-content/themes/your_theme/`)
	- edit the appropriate markup to match your theme
- copy `Templates/Std-WordPress/advanced-search-form.php` to your theme directory (`wp-content/themes/your_theme/`)
	- this can also be an appropriate includes directory if there is one in your theme
	- modify as necessary to meet the design and search needs of the site
- modify `wp-content/themes/your_theme/search.php`
	- add `include('advanced-search-form.php');` where you'd like the search form to be included on the page
	- modify the include path as necessary if `advanced-search-form.php` is in a subdirectory in the theme
- log in to the wordpress admin and create a new page
	- select "CF Advanced Search Page" from the "Page Template" dropdown
- activate the "Advanced Search" plugin via the WordPress Plugins admin page
- add the page to the site navigation according to how the theme's navigation is implemented
- build the search indexes as outlined in the Search Indexes section of this documentation


---

### Carrington Installation

If your site uses the Carrington theme framework:

- add the cf-advanced-search plugin to your plugins directory (`wp-content/plugins`)
- copy `Templates/Carrington/advanced-search.php` to your theme directory (`wp-content/themes/your_theme/`)
	- edit to wrap content area in the appropriate markup to match your theme
- copy `Templates/Carrington/forms/advanced-search-form.php` to your theme's `forms` directory (`wp-content/themes/your_theme/forms/`)
	- modify as necessary to meet the design and search needs of the site
- modify `wp-content/themes/your_theme/loop/search.php`
	- add `cfct_template_file('forms','advanced-search-form');` to the "no search results" section of the page
- log in to the wordpress admin and create a new page
	- select "CF Advanced Search Page" from the "Page Template" dropdown
- activate the "Advanced Search" plugin via the WordPress Plugins admin page
- add the page to the site navigation according to how the theme's navigation is implemented
- build the search indexes as outlined in the Search Indexes section of this documentation


---

### Search Indexes

The initial search index must be built manually. Automated processes can cause too much server overhead and doing a manual update allows more control over the creation of the search indexes in small, manageable batches.

Log in to the WordPress admin go to the search indexes page under "Settings" > "Advanced Search". Here you will see the state of the search indexes. Click "Rebuild Indexes" to do the initial build. Do not leave the page until the in-page update notification box turns green and indicates that indexing is complete.

After the initial build it should not be necessary to manually manage the search indexes. Adding, deleting and editing posts through the WordPress admin automatically manage the search index entries for those posts and pages. It should only be necessary to update the search indexes if posts and/or pages have been updated programmatically and that don't call the built in WordPress save/edit routines.

#### Excluding posts from the search index

The filter `cfs_post_pre_index` is available to remove specific posts from the search indexing.  The filter passes in the `postdata` as a parameter.  If `false` is returned from the filter, the post will not be processed into the search index.


---

### Search Results

When active, the plugin overrides the default WordPress search query with a new one that uses the newly built search indexes. The results are still shown in the default manner on the default search results page. 

**Standard WordPress Theme example of search.php**

	<?php get_header(); ?>

		<div id="content" class="narrowcolumn">

		<?php if (have_posts()) : ?>

			<h2 class="pagetitle">Search Results</h2>
			<?php include (TEMPLATEPATH . '/forms/advanced-search-form.php'); ?>

			<div class="navigation">
				<div class="alignleft"><?php next_posts_link('&laquo; Older Entries') ?></div>
				<div class="alignright"><?php previous_posts_link('Newer Entries &raquo;') ?></div>
			</div>

			<?php while (have_posts()) : the_post(); ?>

				<div class="post">
					<h3 id="post-<?php the_ID(); ?>"><a href="<?php the_permalink() ?>" rel="bookmark" title="Permanent Link to <?php the_title_attribute(); ?>">
					<?php the_title(); ?></a></h3>
					<small><?php the_time('l, F jS, Y') ?></small>

					<p class="postmetadata"><?php the_tags('Tags: ', ', ', '<br />'); ?> Posted in <?php the_category(', ') ?> | <?php edit_post_link('Edit', '', ' | '); ?>  
					<?php comments_popup_link('No Comments &#187;', '1 Comment &#187;', '% Comments &#187;'); ?></p>
				</div>

			<?php endwhile; ?>

			<div class="navigation">
				<div class="alignleft"><?php next_posts_link('&laquo; Older Entries') ?></div>
				<div class="alignright"><?php previous_posts_link('Newer Entries &raquo;') ?></div>
			</div>

		<?php else : ?>

			<h2 class="center">No posts found. Try a different search?</h2>
			<?php include (TEMPLATEPATH . '/forms/advanced-search-form.php'); ?>

		<?php endif; ?>

		</div>

	<?php get_sidebar(); ?>

	<?php get_footer(); ?>

**Carrington example of /loop/search.php body**

Here we put the conditional no-results include above the include for the advanced search form so that in the event of no results returned we can nicely segue from the no-results notification in to prompting the user to try his/her search again. This also allows some built in logic in the advanced-search-form page to detect valid search results and automatically hide itself to allow us to place a "new search" button on the page that shows the form for the user to initiate a new search if desired. 

	if(!have_posts()) {
		cfct_misc('no-results');	
	}
	cfct_template_file('forms','advanced-search-form');
	if (have_posts()) {
		while (have_posts()) {
			the_post();
			cfct_excerpt();
		}
	}
	
	
---

### Global Search

Global search enables a global search index for an entire WordPress MU install. Global search will not turn on if it does not detect WordPress MU. Global Search must be enabled for each site that is to be included in the global search index. If a site is added to the global index AFTER is has performed its first search index build then the indexes must be rebuilt to add all the indexed content to the global search index. As with the regular search index this index is managed automatically with the built in WordPress post/page save, edit and delete functionality. The only reason to rebuild the indexes would be if post/page content was edited programmatically and not using the default WordPress post save functionality.

Global searches are performed by defining a search as global in the search parameters. Any search can address the global index by adding a global search input to a search form. This example adds the ability to toggle advanced search on and off:

	<input type="checkbox" name="cfs_global_search" id="cfs_global_search" class="as-toggle" value="1" <?php
		if(isset($_GET['cfs_global_search']) && $_GET['cfs_global_search'] == '1') { echo 'checked="checked" '; }
	?>/>
	<label for="cfs_global_search">Search all blogs</label>

To automatically turn on global search for a form a hidden element may be used:

	<input type="hidden" name="cfs_advanced_search" value="1" />
	
Global Search must be turned on explicitly and a filter is provided to enable the feature. Below is all you need to do to turn on the Global Search.
	
	function my_set_global_search($global_search) {
		return true;
	}
	add_filter('cfs_do_global_search','my_set_global_search');
	

---

### Search Filters

Searches can be modified to focus on specific categories, authors, or try to match extra keywords against post tags. Searches can also be modified to exclude by those same criteria.

#### Filters

Filters can be used to narrow search results to the applied filters. For example: to perform a search only in the 'newsletters' category a parameter of `cfs_category_filter="newsletters"` should be passed to the search. Multiple filters can be applied to a single search.

Available filters are:

- `cfs_author_filter`
- `cfs_category_filter`
- `cfs_tag_filter`

**Example filter**

	<select id="category_filter" name="cfs_category_filter">
		<option value="newsletters">Newsletters</option>
		<option value="reports">Special Reports</option>
	</select>

#### Excludes

Excludes can be used to exclude information from search results. For example: to exclude anything in the `sweaters` category a parameter of `cfs_category_exclude="sweaters"` should be passed to the search. Multiple excludes can be applied to a single search. The search exclude is not secure as it still uses in page form data and GET variables to do the exclusion. If data needs to be secure and non-searchable it should be excluded from the search indexing.

Available excludes are:

- `cfs_author_exclude`
- `cfs_category_exclude`
- `cfs_tag_exclude`

**Example exclude**

	<input type="hidden" name="cfs_category_exclude" value="sweaters" />