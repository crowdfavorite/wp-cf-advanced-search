<?php
/*
Temporary dumping ground for the multiple author functionality being stripped from the advanced-search plugin
@TODO - change name space
@TODO - remove dependence on cfs_* functions
@TODO - provide accessor functions to the stored data
*/

// ACTIONS

	// post editor modifications
	add_action('admin_head-post-new.php', 'cfs_post_ui');
	add_action('admin_head-post.php', 'cfs_post_ui');
	add_action('admin_head-post-new.php', 'cfs_post_header_scripts');
	add_action('admin_head-post.php', 'cfs_post_header_scripts');
	add_action('save_post', 'cfs_store_custom_metafields', 9999, 1);
	
// POST EDIT UI

	/**
	 * Add multiple authors post-meta box
	 */
	function cfs_post_ui() {
		// author management
		add_meta_box('cfs_post_multiple_authors', __('Multiple Authors'), 'cfs_post_authors_ui', 'post', 'normal', 'low');
	}

	/**
	 * Styles and Javascript needed for picking multiple authors
	 */
	function cfs_post_header_scripts() {
		echo '
			<style type="text/css">
				<!--
					/* Added by CF Advanced Search to style the multi-author select area */
					/* copied from category picker because it was all hardcoded to an ID instead of classed */
					#cfs_post_multiple_authors ul {
						list-style-image:none;
						list-style-position:outside;
						list-style-type:none;
						margin:0;
						padding:0;
					}
					#cfs_post_multiple_authors ul#cfs_multiple_authors_tabs {
						float:left;
						margin:0 -120px 0 0;
						padding:0;
						text-align:right;
						width:120px;
					}
					ul#cfs_multiple_authors_tabs li {
						padding:8px;
					}
					ul#cfs_multiple_authors_tabs li.ui-tabs-selected {
						background-color:#CEE1EF !important;
						-moz-border-radius-bottomleft:3px;
						-moz-border-radius-topleft:3px;
						-webkit-border-bottom-left-radius: 3px;
						-webkit-border-top-left-radius: 3px;
					}
					ul#cfs_multiple_authors_tabs li.ui-tabs-selected a:link {
						color:#333333;
						font-weight:bold;
						text-decoration:none;
					}
	
				-->
			</style>
			<script type="text/javascript">
				// <![CDATA[
					// Added by CF Advanced Search to init the multi-author select area
					jQuery(function() {
						var multipleAuthorTabs = jQuery("#hn_multiple_authors_tabs").tabs();
					});
				// ]]>
			</script>
		';
	}

	function cfs_post_authors_ui() {
		global $post;
	
		// get currently assigned authors
		// returns array of usernames
		$selected_authors = get_post_meta($post->ID,'cfs_authors',false);
		if (!is_array($selected_authors)) { $selected_authors = array(); }
		dbg('selected authors',$selected_authors);
	
		// get full author list
		// returns array of usernames
		$all_authors = cfs_get_authors(true);
		dbg('all authors',$all_authors);
		$all_authors = cf_sort_by_key($all_authors,'user_nicename');
	
		// build output
		$html = '
				<p>Select the authors to assign to this post.</p>
				<ul id="cfs_multiple_authors_tabs" class="ui-tabs-nav">
					<li class="ui-tabs-selected"><a href="#cfs_multiple_authors_list">Authors</a></li>
				</ul>
				<div id="cfs_multiple_authors_list" class="ui-tabs-panel" style="display: block;">
					<ul>
				';
		foreach($all_authors as $author_id => $author) {
			$html .= '<li>
					<label for="cfs_multiple_authors_'.$author->user_nicename.'">'.
					'<input type="checkbox" id="cfs_multiple_authors_'.$author->user_nicename.'" name="cfs_multiple_authors[]" value="'.$author->user_nicename.'"'.
					(in_array($author->user_nicename,$selected_authors) ? ' checked="checked"' : null).
					'> '.
					$author->display_name.
					'</label>'.
					'</li>'.PHP_EOL;
		}
		$html .= '
					</ul>
				</div>
				';
	
		// output
		echo $html;
	}

// SAVE DATA HANDLER

	function cfs_store_custom_metafields($post_id) {
		dbg('cfs_store_custom_metafields', $post_id);
	
		// can have multiple authors per document
		// delete exisitng authors, then insert newly selected ones
		$selected_authors = cfs_param('cfs_multiple_authors', array());
		dbg('selected_authors', $selected_authors);

		delete_post_meta($post_id, 'cfs_authors');
		foreach($selected_authors as $a) {
			add_post_meta($post_id, 'cfs_authors', $a, false);
		}
	
	}
?>