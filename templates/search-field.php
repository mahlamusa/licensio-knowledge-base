<?php
/**
 * Knowledge base search form.
 *
 * The one search form the knowledge base has. The archive and the search
 * results page both render it, and `hero.php` renders it when a view asks for
 * a hero with search in it, so the markup exists once rather than being copied
 * into every view that wants a search box.
 *
 * Override from a theme by copying this file to `licensio-kb/search-field.php`.
 *
 * Deliberately NOT named `search-form`. That name is already the override seam
 * for the search block, which resolves it through `licensio_kb_get_template()`
 * and falls back to its own ARIA combobox markup when no template answers
 * (`Licensio_KB_Search_Block::render()`). A file at `search-form.php` would be
 * picked up there instead and would silently replace the block's combobox,
 * live region and `aria-controls` wiring with this plain form.
 *
 * @package Licensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

?>
<form role="search" method="get" class="licensio-kb__search" action="<?php echo esc_url( home_url( '/' ) ); ?>">
    <label class="licensio-kb__sr-only" for="licensio-kb-search-field">
        <?php esc_html_e( 'Search the knowledge base', 'licensio-knowledge-base' ); ?>
    </label>
    <input
        type="search"
        id="licensio-kb-search-field"
        class="licensio-kb__search-field"
        name="s"
        value="<?php echo esc_attr( get_search_query() ); ?>"
        placeholder="<?php esc_attr_e( 'Search the knowledge base', 'licensio-knowledge-base' ); ?>"
    />
    <input type="hidden" name="post_type" value="<?php echo esc_attr( Licensio_KB_Post_Types::POST_TYPE ); ?>" />
    <button type="submit" class="licensio-kb__button"><?php esc_html_e( 'Search', 'licensio-knowledge-base' ); ?></button>
</form>
