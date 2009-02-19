<?php 
/*
Template Name: CF Advanced Search Page
*/

get_header(); ?>

	<div id="content" class="narrowcolumn">

		<?php if (have_posts()) : while (have_posts()) : the_post(); ?>
		<div class="post" id="post-<?php the_ID(); ?>">
		<h2><?php the_title(); ?></h2>
			<div class="entry">
				<?php the_content('<p class="serif">Read the rest of this page &raquo;</p>'); ?>

					
			</div>
		</div>
		<?php endwhile; endif; ?>
	<?php edit_post_link('Edit this entry.', '<p>', '</p>'); ?>
	
	<?php include('forms/advanced-search-form.php'); ?>
	
	</div>

<?php get_sidebar(); ?>

<?php get_footer(); ?>