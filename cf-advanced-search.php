<?php
/*
Plugin Name: CF Advanced Search
Plugin URI: http://crowdfavorite.com
Description: Advanced search functionality greater than the built in wordpress search
Version: 1.0
Author: Crowd Favorite
Author URI: http://crowdfavorite.com
*/

/*
@TODO - remove individual site from global index on deactivate?
@TODO - be sure to trim filter inputs
@TODO - pre-build category link lists when building post data
		during switch_to_blog
*/

	//error_reporting(E_ALL ^ E_NOTICE);
	//ini_set('display_errors', 1); 

// CONSTANTS & GLOBALS
	
	/**
	 * Development Version
	 */
	define('CFS_SEARCH_VERSION',1.0);
	
	
// ACTIVATION

	load_plugin_textdomain('agora-financial');
	cfs_assign_actions();
	
	// be explicit about the path to handle symlinking
	register_activation_hook(WP_CONTENT_DIR.'/plugins/cf-advanced-search/'.basename(__FILE__), 'cfs_activate');
	function cfs_activate() {
		register_shutdown_function('cfs_initialize_database');
	}

	function cfs_assign_actions() {
		// init handler
		add_action('init','cfs_init_handler');

		// admin page actions
		if (is_admin()) {
			// indexing actions
			add_action('deleted_post', 'cfs_deleted_post');
			add_action('save_post', 'cfs_save_post', 10000, 2);
			add_action('transition_post_status', 'cfs_transition_post_status', null, 3);

			// rebuild index page functions
			add_action('admin_menu','cfs_admin_menu_item');
			if (isset($_GET['page']) && $_GET['page'] == 'advanced-search-admin') {
				add_action('admin_head','cfs_admin_css');
				wp_enqueue_script('cfs-search-admin-js',get_bloginfo('wpurl').'/index.php?cfs-search-admin-js=1',array('jquery'),CFS_SEARCH_VERSION);
			}
		}
		else {
			// modify the sql query in the wp-query object
			add_action('posts_request','cfs_posts_request_action');
			// modify the posts as they're returned from the wp-query posts query
			add_action('the_posts','cfs_posts_results_action'); 
		
			// modify the post url if blog_id is found on the post object
			add_action('post_link','cfs_posts_permalink_action',10,2);
			// modify the author link if blog_id is found on the post object
			add_action('author_link','cfs_author_link_action',10,3);
			// help out the query parsing to ensure our search is performed 
			add_action('request','cfs_parse_query');		
		}
		
	}

// INIT

	/**
	 * init handler
	 */
	function cfs_init_handler() {
		/**
		 * true to allow global search
		 * ie: for parent MU blog to search child blogs
		 */
		define('CFS_GLOBAL_SEARCH',apply_filters('cfs_do_global_search',false));

		/**
		 * Toggle search highlighting
		 */
		define('CFS_HIGHLIGHTSEARCH',apply_filters('cfs_do_search_highlight',true));
		
		if(CFS_HIGHLIGHTSEARCH) {
			define('CFS_HIGHLIGHT_HASH_PREFIX',apply_filters('cfs_search_hightlight_hash_prefix','hl-'));
			wp_enqueue_script('jquery-highlight','/index.php?cfs-search-js',array('jquery'),3);
			wp_enqueue_style('cfs-search-box','/index.php?cfs-search-css',array(),1,'screen');
		}
		
		// deliver externals
		if (isset($_GET['cfs-search-admin-js'])) {
			cfs_admin_js();
			exit();
		}
		elseif(isset($_GET['cfs-search-js'])) {
			cfs_js();
			exit;
		}
		elseif(isset($_GET['cfs-search-css'])) {
			cfs_css();
			exit;
		}
	
		if (function_exists('is_admin_page') && is_admin_page()) {
			// rebuild database indexes
			if (isset($_POST['cfs_rebuild_indexes']) && isset($_POST['cfs_batch_offset'])) {
				cfs_batch_reindex();
				exit();
			}
			else if (isset($_POST['cfs_rebuild_indexes']) && isset($_POST['cfs_create_table_index'])) {
				cfs_build_batch_index();
				exit();
			}
		}
	}

// SEARCH FILTERS
	
	/**
	 * Rewrite the WP_Query query on is_search() == true
	 * @param string $post_query - the default wordpress search query
	 * @return string - a modified query to do an advanced search on our indexed data
	 */
	function cfs_posts_request_action($post_query) {
		global $wp_the_query,$search;
		if (is_search() && isset($wp_the_query->query_vars['advanced-search']) && $wp_the_query->query_vars['advanced-search'] == 1) {
			$options = cfs_search_params();			
			$post_query = cfs_execute_search($options,true);
		}
		return $post_query;
	}

	/**
	 * Attatch the blog name and general permalink to the post for easy retrieval
	 * @TODO - the meat of this function
	 * @uses wp_cache_get & wp_cache_add to store values to avoid repetition
	 * @param array $posts - array of posts from the query object
	 * @return array - possibly modified array of posts
	 */
	function cfs_posts_results_action($posts) {
		global $wp_the_query,$search;
		if (isset($wp_the_query->query_vars['advanced-search']) && $wp_the_query->query_vars['advanced-search'] == 1) {
			if (cfs_param('cfs_global_search') == '1') {
				foreach($posts as $key => $post) {
					$deets = get_blog_details($post->blog_id);
					$posts[$key]->siteurl = $deets->siteurl;
					$posts[$key]->blogname = $deets->blogname;
				}
			}
			// unset the advanced-search var on the query object so that these filters aren't running again
			unset($wp_the_query->query_vars['advanced-search']);
		}
		return $posts;
	}
	
	
// TEMPLATE FILTERS FOR GLOBAL SEARCH
	
	/**
	 * If a blog id is found on the post then use it to pull an author url to that blog
	 *
	 * @uses wp_cache_get & wp_cache_add to store values to avoid repetition
	 * 		- I don't know if the object cache functions correctly on switch_to_blog so I handled this manually
	 * @param string $link - the author link generated by wordpress' default functionality
	 * @param int $author_id - the id of the author
	 * @param string $author_nicename - the nicename of the author
	 * @param string - url to the author's profile on the relevant blog for this post
	 */
	function cfs_author_link_action($link, $author_id, $author_nicename) {
		global $post;
		if (is_search() && isset($post->blog_id) && !isset($post->global_author_url)) {
			$post->global_author_url = '';
			$key = $post->blog_id.'-'.$post->post_author.'-author_link';
			$link = wp_cache_get($key,'global-authors-urls');
			if ($link == false) {
				switch_to_blog($post->blog_id);
				$link = get_author_posts_url($post->post_author);
				restore_current_blog();
				wp_cache_add($key,$link,'global-authors-urls',30);
			}
			$post->global_author_url = $link;
		}
		return $link;
	}
	
	/**
	 * If a blog_id is found on the post then use it to make a permalink for the post to that blog
	 *
	 * @uses wp_cache_get & wp_cache_add to store values to avoid repetition
	 * 		- I don't know if the object cache functions correctly on switch_to_blog so I handled this manually
	 * @param string $permalink - the permalink generated by wordpress' default functionality
	 * @param object $post - the current active post
	 * @return string - link to the post on its relevant blog
	 */
	function cfs_posts_permalink_action($permalink,$post) {
		if (is_search() && isset($post->blog_id) && !isset($post->post_global_permalink)) {
			$post->post_global_permalink = '';
			$key = $post->blog_id.'-'.$post->ID.'-permalink';
			$permalink = wp_cache_get($key,'global-post-permalinks');
			if ($permalink == false) {
				$permalink = get_blog_permalink($post->blog_id,$post->ID);
				wp_cache_add($key,$permalink,'global-post-permalinks');
			}
			$post->post_global_permalink = $permalink;
		}
		return $permalink;
	}
	

// ACTIONS	

	/**
	 * styles needed for index building status messages
	 */
	function cfs_admin_css() {
		echo '
			<style type="text/css">
				<!--
					/* Added by CF Advanced Search for admin styling */
					div.updated.finished { border-color: green; background-color: #E7FFDB; }
					div.updated.error { border-color: red; background-color: #FDEAC9; }
				-->
			</style>
		';
	}

	/**
	 * control activation of search in the event of an empty search var
	 * Search needs SOMETHING to trigger it, so if s is empty then give it at minimum an empty string
	 */
	function cfs_parse_query($query_vars) {
		if (isset($_GET['s'])) {
			if (empty($_GET['s'])) {
				$params = cfs_search_params();
				$query_vars['s'] = ' ';
			}
			$query_vars['advanced-search'] = 1;
		}
		return $query_vars;
	}

	// actions requested through GET vars
	function cfs_request_handler() {
		if ($action = cfs_param('cfs_action')) {
			$fn = "cfs_request_$action";
			if (function_exists($fn)) {
				call_user_func($fn);
			} 
			else {
				dbg('Missing action', $action);
			}
		}
	}

	// remove from index
	function cfs_deleted_post($postid) {
		cfs_deindex_post($postid);
	}

	// add/replace in index
	function cfs_save_post($post_id, $post) {
		cfs_index_post($post);
	}
	
	// we may want to add to the index when we transition to "published"
	// and remove otherwise
	function cfs_transition_post_status($new_status, $old_status, $post) {
		dbg('cfs_transition_post_status', $new_status);
		if ($new_status == 'publish') {
			cfs_index_post($post);
		} 
		else {
			cfs_deindex_post($post->ID);
		}
	}


// ADMIN PAGE

	/**
	 * Admin page wrapper
	 */
	function cfs_admin() {
		echo '
				<div class="wrap cfs_wrap">
					<h2>Advanced Search Admin</h2>
			';
		cfs_rebuild_index_form();
		echo '
				</div>
			';
	}
	
	/**
	 * Rebuild Indexes form
	 */
	function cfs_rebuild_index_form() {
		global $wpdb;
		$status = cfs_index_table_status();
		echo '
				<h3>Search Index</h3>
			';
		if(CFS_GLOBAL_SEARCH) {
			echo '<p>Global Search is enabled. This blog will be searchable via the global search index.</p>';
		}
		echo '
				<div id="cfs-index-info">
					<ul class="index-info">
						<li><strong>Last Full Index:</strong> <span id="cfs_create_time">'.$status->Create_time.'</span></li>
						<li><strong>Last Update (post insert):</strong> <span id="cfs_update_time">'.$status->Update_time.'</span></li>
						<li><strong>Indexed Posts:</strong> <span id="cfs_num_rows">'.$status->Rows.'</span></li>
					</ul>
				</div>
				<h3>Rebuild Search Index</h3>
				<p>Rebuild indexes. This can take a while with large numbers of posts.</p>
				<div id="index-status"><p id="index-status-update"></p></div>
				<form id="cfs_rebuild_indexes_form" method="post" action="" onsubmit="return false;">
					<p class="submit"><input type="submit" name="cfs_rebuild_indexes" value="Rebuild Index"></p>
				</form>
			';
	}
	
	/**
	 * Get some stats on the index table
	 */
	function cfs_index_table_status() {
		global $wpdb;
		$index_table = cfs_get_index_table();
		return $wpdb->get_row("SHOW TABLE STATUS LIKE '{$index_table}'");
	}
	
	/**
	 * Admin page javascript 
	 */
	function cfs_admin_js() {
		header('Content-type: text/javascript');
		?>
jQuery(function() {

	jQuery('#cfs_rebuild_indexes_form input[type="submit"]').click(function(){
		cfs_batch_rebuild_indexes();
		return false;
	});
	
	function cfs_batch_rebuild_indexes() {
		var batch_increment = 100;
		cfs_fade_info_display(.3);
		cfs_update_status('Processing posts');
		cfs_rebuild_batch(0,batch_increment);
	}
	
	// recursive function to rebuild the post index in batches
	function cfs_rebuild_batch(offset,increment) {
		jQuery.post('index.php',{'cfs_rebuild_indexes':1,'cfs_batch_offset':offset,'cfs_batch_increment':increment},function(r){
				if (!r.result && !r.finished) {
					if (r.message) { 
						msg = r.message;
					}
					else {
						msg = 'Fatal Error. Please contact the system administrator.';
					}

					cfs_update_status('<b>Post processing failed!</b> Server said: ' + msg);
					return;
				}
				else if (!r.result && r.finished) {
					cfs_update_status('Creating indexes. Almost done&hellip;');
					setTimeout(cfs_rebuild_indexes,500); // slight pause for effect
				}
				else if (r.result) {
					cfs_update_status(r.message);
					cfs_rebuild_batch(offset+increment,increment);
				}
			},'json');
	}
	
	// make a call to rebuild the fulltext indexes on the search tables
	function cfs_rebuild_indexes() {
		// make call to build table index
		jQuery.post('index.php',{cfs_rebuild_indexes:'1',cfs_create_table_index:'1'},function(response) {
				if (response.result) {
					jQuery('#cfs_create_time').html(response.create_time);
					jQuery('#cfs_update_time').html(response.update_time);
					jQuery('#cfs_num_rows').html(response.num_rows);
					cfs_update_status('Post Indexing Complete.',true);
					cfs_fade_info_display(1);
				}
				else {
					cfs_update_status('Failed creating post table index');
				}
			},'json');		
	}
	
	// update status message
	function cfs_update_status(message,finished) {
		if (finished) {
			jQuery('#index-status').addClass('finished').children('#index-status-warning').remove();
		}
		else {
			jQuery('#index-status.finished').removeClass('finished');
			jQuery('#index-status:not(.updated)').addClass('updated').append('<p id="index-status-warning">Do not leave or refresh this page</p>');
		}
		jQuery('#index-status p#index-status-update').html(message);
	}
	
	// change the opacity of the info display
	function cfs_fade_info_display(amount) {
		jQuery('#cfs-index-info').fadeTo(1000,amount);
	}
	
});
		
		<?php
	}
	
	/**
	 * Add admin menu item only for administrators
	 * Run at admin_menu action
	 */
	function cfs_admin_menu_item() {
		add_submenu_page('options-general.php','CF Advanced Search','CF Advanced Search',10,'advanced-search-admin','cfs_admin');
	}
	
	/**
	 * Batch reindexing function, called via ajax
	 * On first call will destroy and rebuild the index table
	 * Then indexes posts based on increments passed in via ajax
	 */
	function cfs_batch_reindex() {
		global $wpdb;
		if (!is_numeric($_POST['cfs_batch_increment']) || !is_numeric($_POST['cfs_batch_offset'])) {
			echo json_encode(array('result' => false,'message' => 'invalid quantity or offset'));
			exit();
		}
		$qty = (int) $_POST['cfs_batch_increment'];
		$offset = (int) $_POST['cfs_batch_offset'];
		
		// first run, rebuild db table
		if ($offset === 0) {
			cfs_destroy_index_table();
			cfs_create_index_table();
			cfs_destroy_global_indices();
			cfs_clear_from_global_index_table();
		}

		$r = cfs_index_batch_posts($qty,$offset);
		if ($r == true) { // success
			$result = true;
			$finished = false;
			$message = 'Processing posts';
			for($i = 0; $i < ($offset/100); $i++) {
				$message .= ' . ';
			}
		}
		else if ($r == false) { // nothing to process
			$result = false;
			$finished = true;
			$message = 'no posts to process in offset range';
		}
		else { // processing error
			$result = false;
			$finished = false;
			$message = $r;
		}
		$message = $message;
		echo cf_json_encode(array('result' => $result,'finished' => $finished,'message' => $message));
		exit();
	}
	
	/**
	 * If we're doing global searches then remove this tables entries from the global index table
	 */
	function cfs_clear_from_global_index_table() {
		if (!cfs_do_global_index()) { return; }
		
		global $wpdb;
		$global_index_table = cfs_get_global_index_table();
		return $wpdb->query("delete LOW_PRIORITY from {$global_index_table} where blog_id = {$wpdb->blogid}");
	}
	
	/**
	 * Rebuilds the index table for this blog
	 * Called at the end of the Ajax batch index routine so
	 * that indexing posts is separated from table index creation
	 */
	function cfs_build_batch_index() {
		cfs_destroy_indices();
		cfs_create_indices();
		
		// rebuild global indices
		if (cfs_do_global_index()) { 
			cfs_destroy_global_indices();
			cfs_create_global_indices();		
		}
		
		// check for errors...
		
		$status = cfs_index_table_status();
		$result = true;
		$message = '';
		echo cf_json_encode(array('result' => $result,
							   'message' => $message,
							   'create_time' => $status->Create_time,
							   'update_time' => $status->Update_time,
							   'num_rows' => $status->Rows));
		exit();
	}
	
// TABLE AND INDEX MANIPULATION

	/**
	 * handle database initialization
	 * build blog specific table, don't populate it
	 * check to see if global table should be built and build if necessary
	 */
	function cfs_initialize_database() {
		// handle this blog
		cfs_create_index_table();
		//cfs_destroy_indices();
		cfs_create_indices();
		
		// handle global search table
		cfs_create_global_index_table();
		//cfs_destroy_global_indices();
		cfs_create_global_indices();
	}
	
	function cfs_index_batch_posts($qty=100,$offset=0) {
		global $wpdb;
		set_time_limit(10 * 60);
		$posts =& cfs_get_posts($qty,$offset);
		// no posts to process in offset range, return false
		if (!count($posts)) { return false; }
		$posts_pulled = count($posts);
		dbg('post count', count($posts));
		foreach($posts as &$post) {
			cfs_index_post($post);
			if ($wpdb->last_error != '') { return $wpdb->last_error; }
		}
		return true;
	}
	
	// deprecated? only called from cfs_index_all_posts which is way too long a function to call
	function cfs_flush_index_table() {
		global $wpdb;
		$index_table = cfs_get_index_table();
		$sql = "delete from {$index_table}";
		$wpdb->query($sql);
	}
	
	// badly named...
	function &cfs_get_posts($qty=false,$offset=false) {
		global $wpdb;
		$index_table = cfs_get_index_table();
		$sql = "select * from {$wpdb->posts} where post_status = 'publish' and post_parent = 0 and post_type = 'post'";
		if ($qty !== false && $offset !== false) {
			$sql .= " LIMIT {$offset}, {$qty}";
		}
		return $wpdb->get_results($sql);
	}
	
	/**
	 * Create the global index table
	 * Only called on plugin activation
	 * In the interest of query speed this table is significantly different than the per-blog index table
	 * We store complete post data in this index so that the pull includes everything we need without any
	 * queries to get complete post and blog information
	 */
	function cfs_create_global_index_table() {
		// no global table needed if not mu or not searching multiple blogs
		if (!cfs_do_global_index()) { return; }
		
		global $wpdb;
		$index_table = cfs_get_global_index_table();
		
		$sql = "
			CREATE TABLE if not exists {$index_table} (
				`post_id` bigint unsigned not null,
				`post_available` timestamp,
				`title` text,
				`excerpt` text,
				`content` longtext,
				`categories` varchar(255),
				`tags` varchar(255),
				`author` varchar(255),
				`blog_id` bigint unsigned not null, 
				`post_author` bigint(20) NOT NULL default '0',
				`post_date` datetime NOT NULL default '0000-00-00 00:00:00',
				`post_date_gmt` datetime NOT NULL default '0000-00-00 00:00:00',
				`post_content` longtext NOT NULL,
				`post_title` text NOT NULL,
				`post_category` int(4) NOT NULL default '0',
				`post_excerpt` text NOT NULL,
				`post_password` varchar(20) NOT NULL default '',
				`post_name` varchar(200) NOT NULL default '',
				`post_modified` datetime NOT NULL default '0000-00-00 00:00:00',
				`post_modified_gmt` datetime NOT NULL default '0000-00-00 00:00:00',
				`post_content_filtered` text NOT NULL,
				`post_parent` bigint(20) NOT NULL default '0',
				`guid` varchar(255) NOT NULL default '',
				`post_type` varchar(20) NOT NULL default 'post',
				PRIMARY KEY (`post_id`,`blog_id`),
				KEY `blog_id` (`blog_id`)
			) ENGINE=MyISAM;
		";
		return $wpdb->query($sql);
	}
	
	// create a single blog index table for this blog
	function cfs_create_index_table() {
		global $wpdb;
		$index_table = cfs_get_index_table();

		$sql = "
			CREATE TABLE if not exists {$index_table} (
				`post_id` bigint unsigned not null,
				`post_available` timestamp,
				`categories` varchar(255),
				`tags` varchar(255),
				`title` text,
				`excerpt` text,
				`content` longtext,
				`author` varchar(255),
				PRIMARY KEY (`post_id`)
			) ENGINE=MyISAM;
		";
		$res = $wpdb->query($sql);
		//error_log('MySQL Returned: '.$res);
		return $res;
	}

	/**
	 * Destroy fulltext indices on a table
	 * @param bool $global - toggle to operate on global table
	 */	
	function cfs_create_indices($global=false) {
		global $wpdb;
		$index_table = ($global ? cfs_get_global_index_table() : cfs_get_index_table());
		$statements = array(
			"alter table {$index_table} add fulltext `ft_categories` (categories);",
			"alter table {$index_table} add fulltext `ft_tags` (tags);",
			"alter table {$index_table} add fulltext `ft_title` (title);",
			"alter table {$index_table} add fulltext `ft_content` (excerpt,content);",
			"alter table {$index_table} add fulltext `ft_author` (author);"
		);
		foreach($statements as $sql) {
			$wpdb->query($sql);
		}
	}
	
	/**
	 * stub function to create global indices
	 */
	function cfs_create_global_indices() {
		if (!cfs_do_global_index()) { return; }
		return cfs_create_indices(true);
	}
	
	/**
	 * Create fulltext indices on a table
	 * @param bool $global - toggle to operate on global table
	 */
	function cfs_destroy_indices($global=false) {
		global $wpdb;
		$index_table = ($global ? cfs_get_global_index_table() : cfs_get_index_table());
		$statements = array(
			"drop index `ft_tags` on {$index_table};",
			"drop index `ft_categories` on {$index_table};",
			"drop index `ft_title` on {$index_table};",
			"drop index `ft_content` on {$index_table};",
			"drop index `ft_author` on {$index_table};",
		);
		foreach($statements as $sql) {
			$wpdb->query($sql);
		}
	}
	
	/**
	 * stub function to destroy global indices
	 */
	function cfs_destroy_global_indices() {
		if (!cfs_do_global_index()) { return; }
		return cfs_destroy_indices(true);
	}
	
	/**
	 * Drop the index table
	 */
	function cfs_destroy_index_table() {
		global $wpdb;
		$index_table = cfs_get_index_table();
		$sql = "drop table if exists {$index_table}";
		$wpdb->query($sql);
	}
	
	
	
	
// SEARCH & INDEXING
	
	/**
	 * this might be variable under WordPress MU because I will probably need to make
	 * a separate index for each blog
	 * attempts to be smart about not being in WPMU
	 *
	 * @return string - blog post index table name
	 */
	function cfs_get_index_table() {
		global $wpdb;
		if (isset($wpdb->blogid)) {
			return "cfs_" . $wpdb->blogid . "_document_index";
		}
		else {
			return 'cfs_document_index';
		}
	}
	
	/**
	 * return the global search index name
	 * @return string - global search index name
	 */
	function cfs_get_global_index_table() {
		return 'cfs_global_document_index';
	}
	
	/**
	 * Return the table that should be searched in this context
	 * Requires global indexing to be enabled and search param of 'global_search' to be set
	 *
	 * @param object $search - the current search object
	 * @return string - table to be searched
	 */
	function cfs_get_search_table(&$search) {
		if (cfs_do_global_index() && $search->params['global_search'] > 0) {
			return cfs_get_global_index_table();
		}
		else {
			return cfs_get_index_table();
		}
	}
	
	/**
	 * return wether we're doing global search indexing or not
	 * @return bool - true if we're doing global searching & indexing
	 */
	function cfs_do_global_index() {
		global $wpdb;
		return isset($wpdb->blogid) && CFS_GLOBAL_SEARCH;
	}
	
	/**
	 * Indexes incoming post for later fulltext searches by inserting
	 * relevant text strings into a custom indexed table
	 * If global indexing enabled this also inserts into the global index table
	 *
	 * Filters
	 * 	- cfs_post_pre_index: pre-index state of the post for modification
	 *
	 * @param object $post - the post object to be indexed
	 */
	function cfs_index_post($post) {
		// make sure we have an ID
		if (!$post->ID) {
			dbg('cfs_index_post', 'error: missing post ID');
			return;
		}
	
		// don't do anything on drafts or revisions
		//if ($post->post_type == 'revision' || $post->post_status == 'draft' || $post->post_parent != 0) { // post parent check is eliminating sub-pages
		if ($post->post_type == 'revision' || $post->post_status == 'draft') {
			dbg('cfs_index_post','error: post is a draft or revision');
			return;
		}
	
		// start gathering post information, its a bit heavy but easier for applying filters later on
		$postdata['ID'] = $post->ID;
		$postdata['post_title'] = trim(strip_tags($post->post_title));
		$postdata['post_excerpt'] = trim(strip_tags($post->post_excerpt));
		$postdata['post_content'] = trim(strip_tags($post->post_content));				
		
		// all categories belonging to this post, apply a filter for accessibility later on
		$postCats = wp_get_post_categories($post->ID, array('fields' => 'all'));
		$postdata['cats'] = array();
		foreach($postCats as $thisCat) {
			$postdata['cats'][] = $thisCat->name;
		}

		// get tags, apply a filter for accessibility later on
		$postdata['tags'] = wp_get_object_terms($post->ID, 'post_tag', array('fields' => 'names'));
		
		// grab author data and filter to allow for modification of the author data at a later time
		$authordata = get_userdata($post->post_author);
		if (empty($authordata)) { 
			$postdata['author'] = ''; 
		}
		else {
			$postdata['author'] = (!empty($authordata->user_nicename) ? $authordata->user_nicename : $authordata->user_login);
		}

		// apply filter to post data before indexing
		$postdata = apply_filters('cfs_post_pre_index',$postdata);
		// interpret false post data as 'do not index'
		if($postdata === false) {
			return;
		}
		
		// put all that in the index
		$index_table = cfs_get_index_table();
		global $wpdb;
		$sql = trim("
			replace into {$index_table} (post_id, categories, tags, author, title, excerpt, content) 
			values (%d, %s, %s, %s, %s, %s, %s)"
		);
		$qry = $wpdb->prepare($sql,
			$postdata['ID'],
			implode(' ', $postdata['cats']),
			implode(' ', $postdata['tags']),
			$postdata['author'],
			$postdata['post_title'],
			$postdata['post_excerpt'],
			$postdata['post_content']
		);
		$wpdb->query($qry);
		
		if (cfs_do_global_index()) {
			$global_index_table = cfs_get_global_index_table();
			$global_sql = trim("
				replace into {$global_index_table} (post_id, categories, tags, author, post_title, post_excerpt, post_content, post_date, post_date_gmt, post_author, post_category, post_password, ".
													"post_name, post_modified, post_modified_gmt, post_content_filtered, post_parent, guid, post_type, blog_id, title, excerpt, content) 
				values (%d, %s, %s, %s, %s, %s, %s, %s, %s, %d, %d, %s, %s, %s, %s, %s, %d, %s, %s, %d, %s, %s, %s)"
			);
			$global_qry = $wpdb->prepare($global_sql,
				$postdata['ID'],
				implode(' ', $postdata['cats']),
				implode(' ', $postdata['tags']),
				$postdata['author'],
				$post->post_title,
				$post->post_excerpt,
				$post->post_content,
				$post->post_date,
				$post->post_date_gmt,
				$post->post_author,
				$post->post_category,
				$post->post_password,
				$post->post_name,
				$post->post_modified,
				$post->post_modified_gmt,
				$post->post_content_filtered,
				$post->post_parent,
				$post->guid,
				$post->post_type,
				$wpdb->blogid,
				$postdata['post_title'],
				$postdata['post_excerpt'],
				$postdata['post_content']
			);
			$wpdb->query($global_qry);			
		}
	}
	
	/**
	 * Remove a specified post from the index(es)
	 * just delete from the index where postid
	 * Handles both local and global indexes
	 *
	 * @param int $postid - id of the post to remove
	 */
	function cfs_deindex_post($postid = 0) {
		if (!$postid) {
			dbg('cfs_deindex_post', 'aborting: no postID');
			return;
		}
		
		dbg('cfs_deindex_post', $postid);
		global $wpdb;
		$index_table = cfs_get_index_table();
		$sql = "delete LOW_PRIORITY from {$index_table} where post_id = %d";
		$qry = $wpdb->prepare($sql,$postid);
		$wpdb->query($qry);
		
		if (cfs_do_global_index()) {
			$global_index_table = cfs_get_global_index_table();
			$global_sql = "delete LOW_PRIORITY from {$global_index_table} where post_id = %d and blog_id = %d";
			$global_qry = $wpdb->prepare($global_sql,$postid,$wpdb->blogid);
			$wpdb->query($global_qry);
		}
	}

	/**
	 * Independent function to perform a search
	 * Default behavior is to do a search, but can also return a SQL search string to 
	 * pass to wp_query to override the default query
	 *
	 * @param array $options - array of default settings overrides
	 * @param bool $wp_search - wether to perform the search or just return SQL
	 */
	function &cfs_execute_search($options=array(),$wp_search=false) {
		$defaults = cfs_get_search_fields(true);
		$search = new stdclass;
		$search->params = array_merge($defaults, $options);

		cfs_build_search_sql($search);
		
		if (!$wp_search) {
			global $wpdb;		
			$search->results = $wpdb->get_results($search->sql_processed, OBJECT);
			$search->found_rows = $wpdb->get_var('select found_rows()');
			return $search;
		}
		else {
			return $search->sql_processed;
		}		
	}
	
	/**
	 * return a list of search fields for processing/population
	 * Can be toggled to return array of params with defaults
	 * 
	 * @param bool $defaults - wether we're returning a full array with default values or just returning the keys
	 * @return array
	 */
	function cfs_get_search_fields($defaults=false) {
		global $wp_query;
		$fields = array(
			'start_row' => 0, 
			'row_count' => $wp_query->query_vars['posts_per_page'], 
			'search_string' => '', 
			'category_filter' => '', 
			'author_filter' => '', 
			'tag_filter' => '',
			'sort_order' => '',
			/*'search_author' => 0,
			'search_category' => 0,
			'search_tag' => 0,*/
			'author_filter' => '',
			'tag_filter' => '',
			'category_filter' => '',
			'author_exclude' => '',
			'category_exclude' => '',
			'tag_exclude' => '',
			'global_search' => 0,
			'keyword_comparison' => 'or'
		);
		return $defaults ? $fields : array_keys($fields);
	}
	
	/**
	 * Retrieves relevant search params from get/post
	 * Sets appropriate flags for search filtering as well
	 *
	 * Filters
	 * 	- 'cfs_search_params' - modify the params list after its been built
	 *
	 * @return array - processed array of search params
	 */
	function cfs_search_params() {
		global $wp_query;
		
		$params = cfs_get_search_fields(true);
	
		foreach($params as $f => $v) {
			// catch our search filters as IE work around
			if ($f == 'search_tag' || $f == 'search_category' || $f == 'search_author' || $f == 'global_search') {
				if (isset($_POST['cfs_'.$f]) || isset($_GET['cfs_'.$f])) {
					$params[$f] = 1;
				}
			}
			else if ($f == 'search_string' && isset($_GET['s'])) {
				// hijack the WP search variable	
				$params[$f] = $_GET['s'];
			}
			else {
				if ($thisparam = cfs_param("cfs_$f")) {
					$params[$f] = $thisparam;
				}
			}
		}
		
		// handle paginated query
		if ($wp_query->is_paged && isset($_GET['s'])) {
			$params['start_row'] = ($wp_query->query_vars['paged']-1) * $wp_query->query_vars['posts_per_page'];
		}

		return apply_filters('cfs_search_params',count($params) ? $params : null);
	}

	/**
	 * Convert a search string to an array, honoring quotes as phrase delimiters
	 *
	 * @TODO better guesswork closing of open quotes?
	 *
	 * @param string $string 
	 * @return array
	 */
	function cfs_search_string_to_array($string) {
		$terms = array();

		// ghetto solution: simply close an open quote at the end of the search string
		// maybe this should strip out the last one found instead? I don't know.
		if(substr_count($string,'"')%2) {
			$string .= '"';
		}
		
		// grab quoted strings
		$n = preg_match_all('/(".*?")/',$string,$matches);
		$terms = array_merge($terms,$matches[0]);
		$string = preg_replace('/(".*?")/','',$string);
		
		// by default increase a quoted term's relevance
		// don't modify it if a modifier has been supplied
		foreach($terms as &$term) {
			if($term[0] != '>' && $term[0] != '<') {
				$term = '>'.$term;
			}
			$term = '('.$term.')';
		}

		// final extraction by space-delimination
		$terms = array_merge($terms,explode(' ',$string));
		
		// trim & yank empty array elements
		$terms = array_map('trim',$terms);
		$terms = array_filter($terms);
		
		return $terms;
	}
	
	/**
	 * Assembles search string based on request params
	 * Handles does final handling of excludes, sorting and filtering 
	 * 
	 * @param object $search - the current search object
	 */
	function cfs_build_search_sql(&$search) {
		global $wpdb;

		// global search toggles
		if ($search->params['global_search'] > 0) {
			$index_table = cfs_get_global_index_table();
			$fields = '
					post_id as ID,
					post_author,
					post_date,
					post_date_gmt,
					post_title,
					post_name,
					post_excerpt,
					"post" as post_type,
					guid,
					post_content,
					categories,
					tags,
					author,
					blog_id,
				';
			$from = "
				from {$index_table}
				";
		}	
		else {
			$index_table = cfs_get_index_table();
			$fields = '
					p.ID,
					p.post_author,
					p.post_date as post_date,
					p.post_date_gmt,
					p.post_title,
					p.post_name,
					p.post_excerpt,
					p.post_type,
					p.guid,
					p.comment_count,
					p.post_content,
					i.categories,
					i.tags,
					i.author,
				';
			$from = "
				from {$index_table} i
					left join {$wpdb->posts} p on i.post_id = p.ID
				";
		}
		
		$args = array(
			'', // search sql placeholder
			'search_string', 	// relevancy categories
			'search_string', 	// relevancy tags
			'search_string', 	// relevancy title
			'search_string', 	// relevancy content
			'search_string', 	// relevancy authors
			'search_string', 	// match categories
			'search_string', 	// match tags
			'search_string', 	// match title
			'search_string', 	// match excerpt,long_excerpt, content
			'search_string', 	// match authors
			'category_filter', 	// match categories filter a
			'category_filter', 	// match categories filter b
			'author_filter', 	// match authors filter a
			'author_filter', 	// match authors filter b
			'tag_filter', 		// match tags filter a
			'tag_filter' 		// match tags filter b
		);

		// sorting order
		switch($search->params['sort_order']) {
		
			// date
			case 'date':
				$orderby = "post_date desc";
				break;
				
			// relevance
			default:
				$orderby = "relevancy_categories, relevancy_tags, relevancy_title desc, relevancy_content desc, relevancy_authors desc";
				break;
		}

		// build potential exclude lists
		$excludes = '';
		foreach(array('categories' => 'category_exclude', 'author' => 'author_exclude','tags' => 'tags_exclude') as $column => $exclude_type) {
			if (isset($search->params[$exclude_type]) && !empty($search->params[$exclude_type])) {
				$extras .= 'and not match('.$column.') against(\''.$search->params[$exclude_type].'\' IN BOOLEAN MODE) ';
			}
		}

		// handle keyword comparison operators
		if(!empty($search->params['keyword_comparison']) && $search->params['keyword_comparison'] == 'or') {
			// or comparisons, just a stub - no modifications needed
		}
		elseif(!empty($search->params['keyword_comparison']) && $search->params['keyword_comparison'] == 'and') {
			// and comparisons
			$terms = cfs_search_string_to_array($search->params['search_string']);
			$ret = '';
			foreach($terms as &$term) {
				// honor any modifiers that users have already entered
				if($term[0] != '+' && $term[0] != '-') {
					$term = '+'.$term;
				}
			}
			$search->params['search_string'] = implode(' ',$terms);
		}
		elseif(strpos($search->params['search_string'],'"') !== false) {
			// simple quote handling?
		}

		$search->sql = trim("	
select SQL_CALC_FOUND_ROWS
	{$fields}
	match(categories) against (%s IN BOOLEAN MODE) as relevancy_categories,
	match(tags) against (%s IN BOOLEAN MODE) as relevancy_tags,
	match(title) against (%s IN BOOLEAN MODE) as relevancy_title,
	match(excerpt,content) against (%s IN BOOLEAN MODE) as relevancy_content,
	match(author) against (%s IN BOOLEAN MODE) as relevancy_authors

{$from}

where (
		match(categories) against (%s IN BOOLEAN MODE) or
		match(tags) against (%s IN BOOLEAN MODE) or
		match(title) against (%s IN BOOLEAN MODE) or
		match(excerpt,content) against (%s IN BOOLEAN MODE) or 
		match(author) against (%s IN BOOLEAN MODE)
	)
	and (
		('' = %s) or (match(categories) against (%s IN BOOLEAN MODE) > 0)
	)
	and (
		('' = %s) or (match(author) against (%s IN BOOLEAN MODE) > 0)
	)
	and (
		('' = %s) or (match(tags) against (%s IN BOOLEAN MODE) > 0)
	)
	and p.ID is not null

".trim($extras)."

order by {$orderby}
limit %d, %d
		");
		// drop in the real values for argument array
		foreach($args as &$arg) {
			if(isset($search->params[$arg])) {
				$arg = $search->params[$arg];
			}
		}

		$args[] = $search->params['start_row'];	// limit start
		$args[] = $search->params['row_count']; // limit row count
		$args[0] = $search->sql;

		// build sql
		$search->sql_processed = call_user_func_array(array($wpdb,'prepare'),$args);

	}
	
// UTILS

	function cfs_save_option($name, $value) {
		if (get_option($name) === false) {
			add_option($name, $value, $depreciated = '', $autoload = 'no');
		}
		update_option($name, $value);
	}
	
	function cfs_get_option($name, $default = null) {
		if (($val = get_option($name)) == false) {
			return $default;
		}
		return $val;
	}

	function cfs_param($name, $default = null, $scopes = null) {
		if ($scopes == null) {
			$scopes = array($_POST, $_GET);
		}
		foreach($scopes as $thisScope) {
			if (isset($thisScope[$name]) && trim($thisScope[$name]) != '') {
				return !get_magic_quotes_gpc() ? stripslashes($thisScope[$name]) : $thisScope[$name];
			}
		}
		return $default;
	}
	
	function cfs_coalesce() {
		$args = func_get_args();
		foreach($args as $v) {
			if ($v !== null) return $v;
		}
		return null;
	}

	if (!function_exists('dbg')) {
		function dbg($n, $v) {}
	}

	
// UI Helpers

	/**
	 * get all categories from all blogs
	 * stores result in wp_cache to alleviate switch to blog
	 * madness in subsequent requests
	 *
	 * @return array
	 */
	function cfs_get_global_categories() {
		$categories = maybe_unserialize(wp_cache_get('cfs_global_categories'));
		if(is_array($categories)) { return $categories; }
		
		$categories = array();
		$blogs = get_blog_list();
		
		foreach($blogs as $blog) {
			switch_to_blog($blog['blog_id']);
			$categories = array_merge($categories,cfs_get_categories());
			restore_current_blog();
		}
		restore_current_blog();
		
		wp_cache_add('cfs_global_categories',$categories);
		return apply_filters('cfs_get_global_categories',$categories);		
	}

	// retrieves all categories and formats the array for internal use
	function cfs_get_categories() {
		$categories = array();
		$cats = apply_filters('cfs_pre_get_categories',get_categories());
		
		foreach($cats as $cat) {
			$categories[$cat->cat_ID] = $cat->name;
		}
		return apply_filters('cfs_get_categories',$categories);
	}
	

	/**
	 * grab list of authors
	 * pulls anyone with capabilities higher than subscriber
	 * optionally grab the displayname along with the nicename
	 *
	 * @param bool $fullname - true if fullname should be pulled as well, maybe deprecated
	 * @return array - list of authors
	 */
	function cfs_get_authors($fullname=false) {
		global $wpdb;

		$capabilities = (CFS_GLOBAL_SEARCH === true ? '%capabilities' : $wpdb->prefix.'capabilities');
		$sql = "
			SELECT DISTINCT u.ID,
				u.user_nicename,
				u.display_name
			from {$wpdb->users} AS u, 
				{$wpdb->usermeta} AS um
			WHERE u.user_login <> 'admin'
			AND u.ID = um.user_id
			AND um.meta_key LIKE '{$capabilities}'
			AND um.meta_value NOT LIKE '%subscriber%'
			ORDER BY u.user_nicename
			";
		$results = array();
		$users = $wpdb->get_results(apply_filters('cfs_get_authors_sql',$sql));
		foreach($users as $u) {
			$results[$u->ID] = ($fullname ? $u : $u->user_nicename);
		}
		return apply_filters('cfs_get_authors',$results,$fullname);
	}
	
	function cfs_get_sort_options() {
		$sortOptions = array(
			'relevance' => 'Sort by relevance',
			'date' => 'Sort by date'
		);
		return $sortOptions;
	}
	
	function cfs_pulldown($options, $selected_values = null, $useArrayKeyAsValue = true) {
		$html = '';
		if (is_array($options)) {
			foreach($options as $key => $label) {
				$value = ($useArrayKeyAsValue) ? $key : $label;
				$html .= '<option value="'.$value.'">'.$label.'</option>';
			}
			if ($selected_values) {
				if (!is_array($selected_values)) {
					$selected_values = array($selected_values);
				}
				foreach($selected_values as $val) {
					$find = 'value="'.$val.'"';
					$html = str_ireplace($find, $find . ' selected', $html);
				}
			}
		}
		return $html;
	}
	
	/**
	 * Function to build a search freindly list of authors
	 * delivers string of <option> items
	 * 	- key = user_nicename
	 *  - value = display_name
	 *
	 * @param array $authors - list of authors
	 * @param array $selected_values - values that should be pre-selected in the list
	 * @return string
	 */
	function cfs_authors_pulldown($authors, $selected_values=array()) {
		$html = '';
		if (is_array($authors)) {
			if(!is_array($selected_values)) {
				$selected_values = array($selected_values);
			}
			foreach($authors as $id => $author) {
				$selected = (in_array($author->user_nicename,$selected_values) ? ' selected="selected"' : null);
				$html .= '<option value="'.$author->user_nicename.'"'.$selected.'>'.$author->display_name.'</option>';
			}
		}
		return $html;		
	}
	
// Readme

	add_action('admin_init','cfs_add_readme');

	/**
	 * Enqueue the readme function
	 */
	function cfs_add_readme() {
		if(function_exists('cfreadme_enqueue')) {
			cfreadme_enqueue('cfs-readme','cfs_readme');
		}
	}

	/**
	 * return the contents of the links readme file
	 * replace the image urls with full paths to this plugin install
	 *
	 * @return string
	 */
	function cfs_readme() {
		$file = realpath(dirname(__FILE__)).'/install.txt';
		if(is_file($file) && is_readable($file)) {
			$markdown = file_get_contents($file);
			$markdown = preg_replace('|!\[(.*?)\]\((.*?)\)|','![$1]('.WP_PLUGIN_URL.'/cf-links/readme/$2)',$markdown);
			return $markdown;
		}
		return null;
	}
	
	
// Search Term Highlighting

	/**
	 * Add search term to peramlinks for in-post highlighting
	 *
	 * @param string $permalink 
	 * @return string
	 */
	function cfs_search_term_in_permalink($permalink) {
		// try to relegate to main body, this could still fire in the sidebar & nav...
		if(defined('CFS_HIGHLIGHTSEARCH') && CFS_HIGHLIGHTSEARCH && is_search()) {		
			// @TODO check GPC Magic Quotes here to see if we need to do this or not
			$permalink .= '#'.CFS_HIGHLIGHT_HASH_PREFIX.rawurlencode(stripslashes($_GET['s']));
		}
		return $permalink;
	}
	add_filter('the_permalink','cfs_search_term_in_permalink',1000);

	/**
	 * Add javascript to search results page
	 * currently only used to handle search highlighting
	 *
	 * @return void
	 */
	function cfs_js() {
		header('Content-type: text/javascript');
		if(CFS_HIGHLIGHTSEARCH) {
			$js .= file_get_contents(WP_PLUGIN_DIR.'/cf-advanced-search/js/jquery.highlight.js');
			$js .= '
jQuery(function($){
	if(window.location.hash && window.location.hash.match(/#'.CFS_HIGHLIGHT_HASH_PREFIX.'/)) {
		// do highlight
		var terms = decodeURIComponent(window.location.hash.replace("#'.CFS_HIGHLIGHT_HASH_PREFIX.'","")).split(\'"\');
		
		$(terms).each(function(i){
			if(this.length == 0 || this == "undefined") {
				terms.splice(i,1); // remove this item
			}
			else {
				terms[i] = this.replace(/(^\s+|\s+$)/g, "");
			}
		});
		$(".entry-content, .entry-title, .entry-summary").highlight(terms);
		
		// search bar
		searchbar = $("<div id=\'cfs-search-bar\'></div>");
		$("<span>").attr("id","cfs-search-cancel").append($("<a>").attr("href","3").html("close").click(function(){
			$(".entry-content, .entry-title, .entry-summary").unhighlight();
			$("#cfs-search-bar").hide();
			$("body,html").removeClass("cfs-search");
			return false;
		})).appendTo(searchbar);
		$("<b>Search:</b>").appendTo(searchbar);
		$("<a id=\'cfs-search-previous\'>&laquo; Previous</a>").click(function(){
			cfs_next_highlight("prev");
			return false;
		}).appendTo(searchbar);
		$("<a id=\'cfs-search-next\'>Next &raquo;</a>").click(function(){
			cfs_next_highlight("next");
			return false;
		}).appendTo(searchbar);
		$("<span id=\'cfs-search-notice\'>").appendTo(searchbar);
		searchbar.wrapInner(\'<div id="cfs-search-bar-inside">\');
		
		$("body").addClass("cfs-search").prepend(searchbar);
		
		// Fix this thing to the viewport if it is IE.
		if($.browser.msie) {
			function cfasFixSearchBarToViewPortInIE() {
				$(searchbar).css({
					"position": "absolute",
					"top": $(window).scrollTop() + "px"
				});
			}
			cfasFixSearchBarToViewPortInIE();
			$(window).scroll(cfasFixSearchBarToViewPortInIE);
		}
		
		highlighted_items = $(".highlight");
		$(highlighted_items[0]).attr("id","highlight-active")
		current_highlight = 0;
				
		function cfs_next_highlight(dir) {
			if(dir == "next" || dir == "prev") {		
				var next_highlight = dir == "next" ? parseInt(current_highlight)+1 : parseInt(current_highlight)-1;

				var _this = $(highlighted_items[current_highlight]);
				var _next = $(highlighted_items[next_highlight]);
				
				if (dir == "next" && !_next.hasClass("highlight")) { 
					$("#cfs-search-notice").html("No more results. You are at the last item.");
				}
				else if (dir == "prev" && !_next.hasClass("highlight")) {
					$("#cfs-search-notice").html("No more results. You are at the first item.");
				}
				else {
					$("#cfs-search-notice").html("");
					_this.attr("id","");
					_next.attr("id","highlight-active");
					if(dir == "next") {
						current_highlight++;
					}
					else {
						current_highlight--;
					}
					// safari reports absolute position, everyone else reports relative

					$("body,html").animate({ scrollTop: _next.offset().top-100 });
					//$("body,html").animate({ scrollTop:"+=" + (_next.offset().top-100) + "px" });	
				}
			}
			return;
		}
	}
});
			';
		}
		echo $js;
	}
	
	function cfs_css() {
		header('Content-type: text/css');
		
		$header_gradient_base64 = 'iVBORw0KGgoAAAANSUhEUgAAABAAAAAkCAMAAAC3xkroAAAAGXRFWHRTb2Z0d2FyZQBBZG9iZSBJbWFnZVJlYWR5ccllPAAAAEtQTFRF3Nzc6enp4+Pj4ODg7Ozs7+/v2NjY7+/v2dnZ5eXl5ubm39/f3t7e5+fn7u7u4uLi7e3t3d3d6urq2tra5OTk4eHh6+vr29vb6OjoozrPHwAAAGlJREFUeNpkyAkOwjAMRNGhoKRN0p3t/ifFFCHQnydLtr8yaHrLU445zk/4owU8XEB38LCBhxPoCR5GUBnL74tHBTSAruChA83g4QYezqAKarXVmPbdaqAVPJgHeNjBQ+pTH5MOcbwEGABy/iBtDF/dCwAAAABJRU5ErkJggg==';
		
		$highlight_gradient_base64 = 'iVBORw0KGgoAAAANSUhEUgAAAAQAAAAYCAMAAADqO6ysAAAAGXRFWHRTb2Z0d2FyZQBBZG9iZSBJbWFnZVJlYWR5ccllPAAAAEhQTFRF/PWu+ON49ddS9dpd/POo+++b/fez88019dNJ+/Gi8cgj8cYb9+Bv/fi4+umL++yT8MQT778I9NA/78EM9t1m+eaC7r4G8sotyrFsAAAAADxJREFUeNocwQcSgCAAwLC6EAcgzv//lJ4JSQTxG8UiJlFEFo/oxC5uMYheRHGKWXxiFZs4xCWqeNUEGADYpARRkbkfzAAAAABJRU5ErkJggg==';
		
		$lt_gray = '#ccc';
		$dk_gray = '#555';
		$height = 33;
		$highlight_h_padding = '4px';
		$highlight_v_padding = '2px';
		
		$css = '
body.cfs-search {
	margin-top: '.$height.'px;
}
#cfs-search-bar {
	background: #ccc url(data:image/png;base64,'.$header_gradient_base64.') top left repeat-x;
	border-top: 1px solid '.$st_gray.';
	border-bottom: 1px solid '.$dk_gray.';
	height: '.$height.'px;
	left: 0;
	line-height: '.$height.'px;
	margin: 0;
	overflow: hidden;
	position: fixed;
	top: 0;
	width: 100%;
	z-index: 9999;
}
#cfs-search-bar * {
	margin: 0;
	padding: 0;
}
#cfs-search-bar-inside {
	padding:0 20px;
}
#cfs-search-bar a {
	background: #eee;
	border: 1px solid #bbb;
	color:#000;
	padding: 3px;
	margin-left: 10px;
	cursor: pointer;
	-moz-border-radius:5px;
	-webkit-border-radius:5px;
	-khtml-border-radius:5px;
	border-radius:5px;
	font-weight: bold;
}
#cfs-search-bar a:hover {
	border-color: #777;
}
#cfs-search-bar a:active {
	background-color: #ccc;
}
#cfs-search-bar span {
	color: black;
}
#cfs-search-notice {
	margin-left: 10px;
}
#cfs-search-cancel {
	float: right;
}
span.highlight {
	background-color: #fdf8b8;
	padding: '.$highlight_v_padding.' '.$highlight_h_padding.';
	margin: 0 -'.$highlight_h_padding.';
	border: 1px solid '.$lt_gray.';
	border-radius:3px;
	-webkit-border-radius:3px;
	-moz-border-radius:3px;
	-khtml-border-radius:3px;
	white-space: nowrap;
}
span#highlight-active {
	background: #eebe06 url(data:image/png;base64,'.$highlight_gradient_base64.') top left repeat-x;
}
';

$one = '
* {
	margin: 0;
}
html, body {
	height: 100%;
	overflow: auto;
}
* html #cfs-search-bar {
	position: absolute;
}
		';
		
		echo trim($css);
	}
	

?>