<?php
/**
 * Knowledge base search form.
 *
 * The one search form the knowledge base has. The archive and the search
 * results page both render it, and `hero.php` renders it when a view asks for
 * a hero with search in it, so the markup exists once rather than being copied
 * into every view that wants a search box.
 *
 * Override from a theme by copying this file to `lisensio-kb/search-field.php`.
 *
 * Deliberately NOT named `search-form`. That name is already the override seam
 * for the search block, which resolves it through `lisensio_kb_get_template()`
 * and falls back to its own ARIA combobox markup when no template answers
 * (`Lisensio_KB_Search_Block::render()`). A file at `search-form.php` would be
 * picked up there instead and would silently replace the block's combobox,
 * live region and `aria-controls` wiring with this plain form.
 *
 * @package Lisensio_KB
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

?>
<form role="search" method="get" class="lisensio-kb__search" action="<?php echo esc_url( home_url( '/' ) ); ?>">
    <label class="lisensio-kb__sr-only" for="lisensio-kb-search-field">
        <?php esc_html_e( 'Search the knowledge base', 'lisensio-knowledge-base' ); ?>
    </label>
    <input
        type="search"
        id="lisensio-kb-search-field"
        class="lisensio-kb__search-field"
        name="s"
        value="<?php echo esc_attr( get_search_query() ); ?>"
        placeholder="<?php esc_attr_e( 'Search the knowledge base', 'lisensio-knowledge-base' ); ?>"
    />
    <input type="hidden" name="post_type" value="<?php echo esc_attr( Lisensio_KB_Post_Types::POST_TYPE ); ?>" />
    <button type="submit" class="lisensio-kb__button"><?php esc_html_e( 'Search', 'lisensio-knowledge-base' ); ?></button>
</form>
