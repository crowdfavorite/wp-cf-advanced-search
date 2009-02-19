<?php
global $wp_query;

// set up page vars for display
$params = cfs_search_params();
$cats = (CFS_GLOBAL_SEARCH ? cfs_get_global_categories() : cfs_get_categories());
$categorySelect = cfs_pulldown($cats, cfs_param('category_filter', '', array($params)), false);
$authorSelect = cfs_authors_pulldown(cfs_get_authors(true), cfs_param('author_filter', '', array($params)));
$relevanceSelect = cfs_pulldown(cfs_get_sort_options(), cfs_param('sort_order', '', array($params)), true);

// set up some display vars & fancy-dancy javascript fun
$search_div_class = 'advanced-search';
if(is_search() && have_posts()) {
	$for = null;
	if(isset($params['search_string']) && trim($params['search_string']) !== '') {
		$for = 'for "<b>'.$wp_query->query_vars['s'].'</b>"';
	}
	
	$in = null;
	if(isset($params['category_filter'])) {
		$in[] = 'in categories for "<b>'.$params['category_filter'].'</b>"';
	}
	if(isset($params['keyword_filter'])) {
		$in[] = 'in tags for "<b>'.$params['keyword_filter'].'</b>"';
	}
	if(isset($params['author_filter'])) {
		$in[] = 'in authors for "<b>'.$params['author_filter'].'</b>"';
	}
	
	if($in !== null) {
		$in = ($for !== null ? ' and ' : '').implode(' and ',$in).' ';
	}
	
	echo '<p id="search-info">Your '.(CFS_GLOBAL_SEARCH === true ? 'global ' : '').'search '.$for.' '.$in.'returned "<b>'.$wp_query->found_posts.'</b>" results'.
		 (isset($_GET['cfs_global_search']) && $_GET['cfs_global_search'] == 1 ? ' from all blogs' : '').'. '.
		 '<a id="advanced-search-toggle" href="#">New Search</a></p>';	
	$search_div_class .= ' closed';
	echo '
		<script type="text/javascript">
			//<[CDATA[
				jQuery(function(){
					jQuery("#advanced-search-toggle").click(function(){
						jQuery("#advanced-search").slideToggle();
					});
				});
			//]]>
		</script>
		';
}
else {
	echo '
		<script type="text/javascript">
			//<[CDATA[
				jQuery(function(){
					jQuery("#cfs_search_string").focus();
				});
			//]]>
		</script>
		';
}

// throw up a weenie message if no search criteria were defined
if(isset($_GET['cfs_empty_search'])) {
	echo '
		<div class="notice">
			<p><b style="color: red;">Please enter search criteria to perform a search</b></p>
		</div>
		';
}
?>
<div id="advanced-search" class="<?php echo $search_div_class; ?>" <?php if(is_search() && have_posts()) { echo 'style="display: none;"'; } ?>>
	<form method="get" action="/" id="cfs-advanced-search">
		<fieldset id="standard-search">
			<legend>Search for:</legend>
			
			<div id="search-term">
				<label for="cfs_search_string">Search Term</label>
				<input type="text" name="s" id="cfs_search_string" value="<?php echo $wp_query->query_vars['s']; ?>" tabindex="100" />
			</div>

			<div id="sort-order">
				<label for="cfs_sort_order">Sort by</label>
				<select name="cfs_sort_order" id="cfs_sort_order" tabindex="101">
					<?php echo $relevanceSelect; ?>
				</select>
			</div>
		</fieldset>
		
		<fieldset id="search-filters">
			<legend>Filter on:</legend>
			
			<div id="category-filter">
				<label for="cfs_category_filter">Topic</label>
				<select id="cfs_category_filter" name="cfs_category_filter" tabindex="102">
					<option value="">Any Category</option>
					<?php echo $categorySelect; ?>
				</select>
			</div>

			<div id="author-filter">
				<label for="cfs_author_filter">Author</label>						
				<select name="cfs_author_filter" tabindex="103">
					<option value="">Any Author</option>
					<?php echo $authorSelect; ?>
				</select>
			</div>

			<div id="tag-filter">
				<label for="cfs_tag_filter">Keyword</label>
				<input type="text" name="cfs_tag_filter" value="<?php echo($search->params['keyword_filter']); ?>"  tabindex="104" />
			</div>
			
<?php
	if(CFS_GLOBAL_SEARCH) {
		echo '
			<div id="global-search">
				<input type="checkbox" name="cfs_global_search" id="cfs_global_search" class="as-toggle" value="1" ';
	if(isset($_GET['cfs_global_search']) && $_GET['cfs_global_search'] == '1') { echo 'checked="checked" '; }
	echo 'tabindex="105" />
				<label for="cfs_global_search">Search all blogs</label>
			</div>
			';
	}
?>
		</fieldset>
		<p class="submit">
			<input name="cfs-advanced-search-submit" id="cfs-advanced-search-submit" type="submit" class="submit" value="Search" tabindex="106" />
		</p>
	</form>

</div><!--#advanced-search-->
