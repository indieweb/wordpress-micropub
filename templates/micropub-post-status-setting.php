<fieldset id="micropub">
        <label for="micropub_default_post_status">
                <select name="micropub_default_post_status" id="micropub_default_post_status">
                        <?php $mpstatus = get_option( 'micropub_default_post_status' ); ?>
                        <option value="publish" <?php selected( 'publish', $mpstatus ); ?>><?php _e( 'Published', 'micropub' ); ?></option>
                        <option value="draft" <?php selected( 'draft', $mpstatus ); ?>><?php _e( 'Draft', 'micropub' ); ?></option>
                        <option value="private" <?php selected( 'private', $mpstatus ); ?>><?php _e( 'Visibility: Private', 'micropub' ); ?></option>
                </select>
        </label>
</fieldset>

