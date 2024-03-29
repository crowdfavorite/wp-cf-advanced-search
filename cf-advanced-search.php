<?php
/*
Plugin Name: CF Advanced Search
Plugin URI: http://crowdfavorite.com
Description: Advanced search functionality greater than the built in wordpress search
Version: 1.0.6
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
	define('CFS_SEARCH_VERSION','1.0.6');
	
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
		
		// deliver externals
		if (isset($_GET['cfs-search-admin-js'])) {
			cfs_admin_js();
			exit();
		}
	
		if (function_exists('is_admin') && is_admin() && strtolower($_SERVER['REQUEST_METHOD']) == 'post') {
			// rebuild database indexes
			// if (isset($_POST['cfs_rebuild_indexes']) && isset($_POST['cfs_batch_offset'])) {
			// 	cfs_batch_reindex();
			// 	exit();
			// }
			// else if (isset($_POST['cfs_rebuild_indexes']) && isset($_POST['cfs_create_table_index'])) {
			// 	cfs_build_batch_index();
			// 	exit();
			// }
			
			switch(true) {
				case isset($_POST['cfs_rebuild_indexes']):
					// batch re-index single blog
					if (isset($_POST['cfs_batch_offset'])) {
						cfs_batch_reindex();
					}
					elseif (isset($_POST['cfs_create_table_index'])) {
						cfs_build_batch_index();
					}
					exit;
					break;
				case isset($_POST['cfs_prune_global_index']):
					// prune global posts table of inactive blog posts
					if(isset($_POST['cfs_rebuild_global_index'])) {
						cfs_rebuild_global_index();
					}
					else {
						cfs_prune_global_index();
					}
					exit;
					break;
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
			remove_action('post_link','cfs_posts_permalink_action',10,2);
			$post->post_global_permalink = '';
			$key = $post->blog_id.'-'.$post->ID.'-permalink';
			$permalink = wp_cache_get($key,'global-post-permalinks');
			if ($permalink == false) {
				$permalink = get_blog_permalink($post->blog_id,$post->ID);
				wp_cache_add($key,$permalink,'global-post-permalinks');
			}
			$post->post_global_permalink = $permalink;
			add_action('post_link','cfs_posts_permalink_action',10,2);
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
		global $wpdb;
		if (isset($_GET['s'])) {
			if (empty($_GET['s'])) {
				$params = cfs_search_params();
				$query_vars['s'] = ' ';
			}
			$query_vars['s'] = $wpdb->prepare('%s', $query_vars['s']);
			$_GET['s'] = htmlentities($_GET['s']);
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
					';
		if(function_exists('screen_icon')) {
			screen_icon();
		}		
		echo '<h2>Advanced Search Admin</h2>
			';
		cfs_rebuild_index_form();
		cfs_prune_global_index_form();
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
				<h4>Rebuild Search Index</h4>
				<p>Rebuild indexes. This can take a while with large numbers of posts.</p>
				<div id="index-status"><p class="index-status-update"></p></div>
				<form id="cfs_rebuild_indexes_form" method="post" action="" onsubmit="return false;">
					<p class="submit"><input type="submit" name="cfs_rebuild_indexes" value="Rebuild Index" class="button-primary" /></p>
				</form>
			';
	}
	
	/**
	 * Show global search prune button
	 * only show if 
	 *
	 * @return void
	 */
	function cfs_prune_global_index_form() {
		if(!CFS_GLOBAL_SEARCH || (CFS_GLOBAL_SEARCH && !is_main_blog())) { return; }		
		
		$status = cfs_global_index_table_status();
		echo '
			<h3>Global Search Index</h3>
			<div id="cfs-global-index-info">
				<ul class="index-info">
					<li><strong>Last Full Index:</strong> <span id="cfs_global_create_time">'.$status->Create_time.'</span></li>
					<li><strong>Last Update (post insert):</strong> <span id="cfs_global_update_time">'.$status->Update_time.'</span></li>
					<li><strong>Indexed Posts:</strong> <span id="cfs_global_num_rows">'.$status->Rows.'</span></li>
					<li><strong>Blogs Indexed:</strong> <span id="cfs_global_num_blogs">'.$status->Blogs.'</span></li>
				</ul>
			</div>
			
			<h4>Prune Global Search Index</h4>
			<p>In the case that a blog is deactivated, archived or deleted its posts need to be removed from the global search index. Due to the potential run time of that kind of process it cannot be done automatically. If you delete a blog use the button below to prune the global search index of all posts from blogs that are not active.</p>
			<div id="prune-status"><p class="index-status-update"></p></div>
			<form id="cfs_prune_global_index_form" method="post" action="" onsubmit="return false;">
				<p class="submit"><input type="submit" name="cfs_prune_global_index" value="Prune Global Index" class="button-primary" /></p>
			</form>
			';
	}
	
	/**
	 * Get some stats on the index table
	 */
	function cfs_index_table_status($global = false) {
		global $wpdb;
		$index_table = $global ? cfs_get_global_index_table() : cfs_get_index_table();
		return $wpdb->get_row("SHOW TABLE STATUS LIKE '{$index_table}'");
	}
	
	function cfs_global_index_table_status() {
		global $wpdb;
		$status = cfs_index_table_status(true);
		$index_table = cfs_get_global_index_table();
		$status->Blogs = $wpdb->query("SELECT blog_id, count(blog_id) FROM cfs_global_document_index GROUP BY blog_id");
		return $status;
	}
	
	/**
	 * Admin page javascript 
	 */
	function cfs_admin_js() {
		header('Content-type: text/javascript');
		?>
jQuery(function($) {

// global index prune
	$('#cfs_prune_global_index_form input[type="submit"]').click(function(){
		cfs_prune_global_index();
		return false;
	});

	cfs_prune_global_index = function() {
		cfs_fade_info_display(.3,true);
		cfs_update_status('Pruning posts',false,true);
		cfs_prune_global_index_posts();
	}
	
	cfs_prune_global_index_posts = function() {
		$.post('index.php',{'cfs_prune_global_index':1},function(r){
			if (!r.result && !r.finished) {
				if (r.message) { 
					msg = r.message;
				}
				else {
					msg = 'Fatal Error. Please contact the system administrator.';
				}
				cfs_update_status('<b>Post pruning failed!</b> Server said: ' + msg,false,true);
				return;
			}
			else if (r.finished) {
				cfs_update_status('Updating Index. Almost Done&hellip;',false,true);
				setTimeout(cfs_rebuild_global_index,500);
			}
		},'json');
	}

	cfs_rebuild_global_index = function() {
		$.post('index.php',{'cfs_prune_global_index':1,'cfs_rebuild_global_index':1},function(r){
			if (r.result) {
				var change = parseInt($('#cfs_global_num_blogs').html()) - parseInt(r.num_blogs);
				var message = 'Global Post Pruning Complete. <b>' + change + '</b> blogs&rsquo; posts deleted from the global index';
				$('#cfs_global_create_time').html(r.create_time);
				$('#cfs_glbal_update_time').html(r.update_time);
				$('#cfs_global_num_rows').html(r.num_rows);
				$('#cfs_global_num_blogs').html(r.num_blogs);
				cfs_update_status(message,true,true);
				cfs_fade_info_display(1,true);				
			}
			else {
				cfs_update_status('Failed updating global post index',false,true);
			}
		},'json');
	}

// local index rebuild
	$('#cfs_rebuild_indexes_form input[type="submit"]').click(function(){
		cfs_batch_rebuild_indexes();
		return false;
	});
		
	function cfs_batch_rebuild_indexes() {
		var batch_increment = 100;
		cfs_fade_info_display(.3);
		cfs_update_status('Processing posts',false);
		cfs_rebuild_batch(0,batch_increment);
	}
	
	// recursive function to rebuild the post index in batches
	function cfs_rebuild_batch(offset,increment) {
		$.post('index.php',{'cfs_rebuild_indexes':1,'cfs_batch_offset':offset,'cfs_batch_increment':increment},function(r){
			if (!r.result && !r.finished) {
				if (r.message) { 
					msg = r.message;
				}
				else {
					msg = 'Fatal Error. Please contact the system administrator.';
				}

				cfs_update_status('<b>Post processing failed!</b> Server said: ' + msg,false);
				return;
			}
			else if (!r.result && r.finished) {
				cfs_update_status('Creating indexes. Almost done&hellip;',false);
				setTimeout(cfs_rebuild_indexes,500); // slight pause for effect
			}
			else if (r.result) {
				cfs_update_status(r.message,false);
				cfs_rebuild_batch(offset+increment,increment);
			}
		},'json');
	}
	
	// make a call to rebuild the fulltext indexes on the search tables
	function cfs_rebuild_indexes() {
		// make call to build table index
		$.post('index.php',{cfs_rebuild_indexes:'1',cfs_create_table_index:'1'},function(response) {
			if (response.result) {
				$('#cfs_create_time').html(response.create_time);
				$('#cfs_update_time').html(response.update_time);
				$('#cfs_num_rows').html(response.num_rows);
				cfs_update_status('Post Indexing Complete.',true);
				cfs_fade_info_display(1);
			}
			else {
				cfs_update_status('Failed creating post table index',false);
			}
		},'json');		
	}
	
	// change the opacity of the info display
	function cfs_fade_info_display(amount,global) {
		var fade_tgt = global == true ? 'cfs-global-index-info' : 'cfs-index-info'; 
		$('#' + fade_tgt).fadeTo(1000,amount);
	}
	
// update status messages
	function cfs_update_status(message,finished,global) {
		var msg_tgt = global == true ? 'prune-status' : 'index-status';
		if (finished) {
			$('#' + msg_tgt).addClass('finished').children('.index-status-warning').remove();
		}
		else {
			$('#' + msg_tgt + '.finished').removeClass('finished');
			$('#' + msg_tgt + ':not(.updated)').addClass('updated').append('<p class="index-status-warning">Do not leave or refresh this page</p>');
		}
		$('#' + msg_tgt + ' p.index-status-update').html(message);
	}
	
});
		
		<?php
	}
	
	/**
	 * Add admin menu item only for administrators
	 * Run at admin_menu action
	 */
	function cfs_admin_menu_item() {
		add_submenu_page('options-general.php','CF Advanced Search','CF Advanced Search','administrator','advanced-search-admin','cfs_admin');
	}
	
	/**
	 * Prune the global posts table of posts that are from inactive, deleted or archived blogs.
	 * Called via ajax.
	 *
	 * @return void
	 */
	function cfs_prune_global_index() {
		global $wpdb;
		
		// get complete active blog list - modification of WP's get_blog_list but discards the blog_id and pulls all known blogs...
		$query = "SELECT blog_id FROM $wpdb->blogs 
				WHERE public = '1' 
				AND archived = '0' 
				AND mature = '0' 
				AND spam = '0' 
				AND deleted = '0' 
				ORDER BY registered DESC";
		$blogs = $wpdb->get_results( $query, ARRAY_A );
		//error_log(print_r($blogs,true));
		foreach($blogs as $blog) {
			$where[] = 'blog_id <> '.$blog['blog_id'];
		}
		$where = trim(implode(' AND ',$where));
		
		// prune posts not in those IDs
		$index_table = cfs_get_global_index_table();
		$ret = $wpdb->query("DELETE LOW_PRIORITY FROM {$index_table} WHERE {$where}");

		if($ret === false) {
			echo cf_json_encode(array('result' => false, 'message' => 'No posts deleted'));
		}
		else {
			echo cf_json_encode(array('result' => true, 'finished' => true, 'message' => 'prune successful'));
		}
		exit;
	}
	
	/**
	 * Rebuild just the global search index, by request only, via ajax
	 *
	 * @return void
	 */
	function cfs_rebuild_global_index() {
		// rebuild global indices
		if (cfs_do_global_index()) { 
			cfs_destroy_global_indices();
			cfs_create_global_indices();		
		}
		
		$status = cfs_global_index_table_status();
		echo cf_json_encode(array(
			'result' => true,
			'message' => 'Global Index Rebuilt',
			'create_time' => $status->Create_time,
			'update_time' => $status->Update_time,
			'num_rows' => $status->Rows,
			'num_blogs' => $status->Blogs
		));
	}
	
	/**
	 * Batch reindexing function, called via ajax
	 * On first call will destroy and rebuild the index table
	 * Then indexes posts based on increments passed in via ajax
	 */
	function cfs_batch_reindex() {
		global $wpdb;
		if (!is_numeric($_POST['cfs_batch_increment']) || !is_numeric($_POST['cfs_batch_offset'])) {
			echo cf_json_encode(array('result' => false,'message' => 'invalid quantity or offset'));
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
			cfs_upgrade_104();
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
		cfs_upgrade_104();
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
		$sql = "select * from {$wpdb->posts} where post_status = 'publish' and post_parent = 0";//and post_type = 'post'";
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
		
		$post_type_obj = get_post_type_object($post->post_type);
		if ($post_type_obj->exclude_from_search) {
			dbg('cfs_index_post', 'error: post type does not support search');
			return;
		}
	
		// don't do anything on drafts or revisions
		//if ($post->post_type == 'revision' || $post->post_status == 'draft' || $post->post_parent != 0) { // post parent check is eliminating sub-pages
		if ($post->post_type == 'revision' || $post->post_status == 'draft' || $post->post_status == 'future') {
			dbg('cfs_index_post','error: post is a draft or revision');
			return;
		}
	
		// start gathering post information, its a bit heavy but easier for applying filters later on
		$postdata['ID'] = $post->ID;
		$postdata['post_title'] = trim(strip_tags($post->post_title));
		$postdata['post_excerpt'] = trim(strip_tags($post->post_excerpt));
		$postdata['post_content'] = trim(strip_tags($post->post_content));				
		$postdata['post_type'] = trim(strip_tags($post->post_type));				
		
		
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
			replace into {$index_table} (post_id, categories, tags, author, title, excerpt, content, type) 
			values (%d, %s, %s, %s, %s, %s, %s, %s)"
		);
		$qry = $wpdb->prepare($sql,
			$postdata['ID'],
			implode(' ', $postdata['cats']),
			implode(' ', $postdata['tags']),
			$postdata['author'],
			$postdata['post_title'],
			$postdata['post_excerpt'],
			$postdata['post_content'],
			$postdata['post_type']
		);
		$wpdb->query($qry);
		
		if (cfs_do_global_index()) {
			$global_index_table = cfs_get_global_index_table();
			$global_sql = trim("
				replace into {$global_index_table} (post_id, categories, tags, author, post_title, post_excerpt, post_content, post_date, post_date_gmt, post_author, post_category, post_password, ".
													"post_name, post_modified, post_modified_gmt, post_content_filtered, post_parent, guid, post_type, blog_id, title, excerpt, content) 
				values (%d, %s, %s, %s, %s, %s, %s, %s, %s, %d, %d, %s, %s, %s, %s, %s, %d, %s, %s, %d, %s, %s, %s)"
			);
			$query_args = array(
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
				!empty($post->post_category) ? $post->post_category[0] : 0,
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
			$global_qry = $wpdb->prepare($global_sql, $query_args);
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
			'type_exclude' => '',
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
	 * @param bool $search_optimized - if set to false no search augmented parameters are added
	 * @return array
	 */
	function cfs_search_string_to_array($string,$search_optimized=true) {
		$terms = array();
		
		// handle slashes
		if(!get_magic_quotes_gpc()) {
			$string = stripslashes($string);
		}

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
		if($search_optimized) {
			foreach($terms as &$term) {
				if($term[0] != '>' && $term[0] != '<') {
					$term = '>'.$term;
				}
				$term = '('.$term.')';
			}
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
		$extras = '';

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
					type,
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
				$orderby = "relevancy_categories, relevancy_tags, relevancy_title desc, relevancy_content desc, relevancy_authors desc, p.post_date desc";
				break;
		}

		// build potential exclude lists
		foreach(array('categories' => 'category_exclude', 'author' => 'author_exclude','tags' => 'tag_exclude', 'type' => 'type_exclude') as $column => $exclude_type) {
			if (isset($search->params[$exclude_type]) && !empty($search->params[$exclude_type])) {
				if (!is_array($search->params[$exclude_type])) {
					$extras .= 'and not match('.$column.') against(\''.$search->params[$exclude_type].'\' IN BOOLEAN MODE) ';
				}
				else {
					foreach ($search->params[$exclude_type] as $exclude) {
						$extras .= $wpdb->prepare('and not match('.$column.') against (%s IN BOOLEAN MODE) ', $exclude);
					}
				}
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
	".apply_filters('cfs-search-fields', $fields)."
	match(categories) against (%s IN BOOLEAN MODE) as relevancy_categories,
	match(tags) against (%s IN BOOLEAN MODE) as relevancy_tags,
	match(title) against (%s IN BOOLEAN MODE) as relevancy_title,
	match(excerpt,content) against (%s IN BOOLEAN MODE) as relevancy_content,
	match(author) against (%s IN BOOLEAN MODE) as relevancy_authors

".apply_filters('cfs-search-from', $from)."

where (
		match(categories) against (%s IN BOOLEAN MODE) or
		match(tags) against (%s IN BOOLEAN MODE) or
		match(title) against (%s IN BOOLEAN MODE) or
		match(excerpt,content) against (%s IN BOOLEAN MODE) or 
		match(author) against (%s IN BOOLEAN MODE)
	)
	and (
		('' = %s) or categories LIKE %s
	)
	and (
		('' = %s) or (match(author) against (%s IN BOOLEAN MODE) > 0)
	)
	and (
		('' = %s) or (match(tags) against (%s IN BOOLEAN MODE) > 0)
	)
    ".($search->params['global_search'] > 0 ? null : "and p.ID is not null")."

".apply_filters('cfs-search-extras', trim($extras), $search)."

order by ".apply_filters('cfs-search-order', $orderby)."
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
	
	function cfs_search_title_link() {
		$s = get_query_var('s');
		if (get_option('permalink_structure') != '') {
			$search_title = '<a href="'.esc_attr(trailingslashit(get_bloginfo('url')).'search/'.urlencode($s)).'">'.esc_html($s).'</a>';
		}
		else {
			$search_title = '<a href="'.esc_attr(trailingslashit(get_bloginfo('url')).'?s='.urlencode($s)).'">'.esc_html($s).'</a>';
		}
		return $search_title = apply_filters('cfs-search-title-link',$search_title);
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
	
	function cfs_upgrade_104() {
		global $wpdb;
		$index_table = cfs_get_index_table();
		$wpdb->query("
			ALTER TABLE {$index_table} 
			ADD `type` VARCHAR(255) DEFAULT 'post'
		");
	}
?>
