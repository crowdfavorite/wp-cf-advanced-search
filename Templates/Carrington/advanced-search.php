<?php
/*
Template Name: CF Advanced Search Page
*/

// This file is part of the Carrington Theme for WordPress
// http://carringtontheme.com
//
// Copyright (c) 2008 Crowd Favorite, Ltd. All rights reserved.
// http://crowdfavorite.com
//
// Released under the GPL license
// http://www.opensource.org/licenses/gpl-license.php
//
// **********************************************************************
// This program is distributed in the hope that it will be useful, but
// WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. 
// **********************************************************************

if (__FILE__ == $_SERVER['SCRIPT_FILENAME']) { die(); }
if (CFCT_DEBUG) { cfct_banner(__FILE__); }

get_header();
?>
	<div id="content" class="grid_8">
<?php
	// run content to get title and any descriptive text entered by the editor
	cfct_content();
	// show search form
	cfct_form('advanced-search-form');
?> 
	</div><!--#content-->
<?php
get_sidebar();

get_footer();

?>