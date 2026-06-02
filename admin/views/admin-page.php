<?php
/**
 * Admin tool page markup.
 *
 * Provided by Plugin::render_page():
 *
 * @var array      $stories    List of stories (export listing).
 * @var array|null $result     Last action result payload.
 * @var string     $active_tab Current tab ('export'|'import').
 *
 * @package Ekwa\WebStoriesExportImport
 */

namespace Ekwa\WSEI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$export_url = admin_url( 'admin.php?page=' . Plugin::MENU_SLUG . '&tab=export' );
$import_url = admin_url( 'admin.php?page=' . Plugin::MENU_SLUG . '&tab=import' );
?>
<div class="wrap ekwa-wsei">
	<h1><?php esc_html_e( 'Web Stories Export / Import', 'ekwa-wsei' ); ?></h1>
	<p class="description">
		<?php esc_html_e( 'Move Google Web Stories — together with every image, video, poster and publisher-logo asset — between WordPress sites. Ideal for redesigns and migrations.', 'ekwa-wsei' ); ?>
	</p>

	<?php if ( ! Helpers::web_stories_active() ) : ?>
		<div class="notice notice-warning">
			<p><?php esc_html_e( 'The Google "Web Stories" plugin does not appear to be active on this site. Importing still works, but the imported stories will only be editable once Web Stories is active.', 'ekwa-wsei' ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( $result && 'error' === ( $result['type'] ?? '' ) ) : ?>
		<div class="notice notice-error is-dismissible"><p><?php echo esc_html( $result['message'] ); ?></p></div>
	<?php endif; ?>

	<?php if ( $result && 'success' === ( $result['type'] ?? '' ) && ! empty( $result['import'] ) ) : ?>
		<?php $imp = $result['import']; ?>
		<div class="notice notice-success">
			<p>
				<strong><?php esc_html_e( 'Import complete.', 'ekwa-wsei' ); ?></strong>
				<?php
				printf(
					/* translators: 1: number of stories, 2: assets imported, 3: assets total */
					esc_html__( 'Imported %1$d stories and %2$d of %3$d assets.', 'ekwa-wsei' ),
					(int) count( $imp['stories'] ),
					(int) $imp['assets_imported'],
					(int) $imp['assets_total']
				);
				if ( ! empty( $imp['assets_reused'] ) ) {
					echo ' ' . esc_html(
						sprintf(
							/* translators: %d: number of reused assets */
							_n( '%d shared asset was reused across batches.', '%d shared assets were reused across batches.', (int) $imp['assets_reused'], 'ekwa-wsei' ),
							(int) $imp['assets_reused']
						)
					);
				}
				if ( ! empty( $imp['source_site'] ) ) {
					echo ' ' . esc_html( sprintf( __( 'Source site: %s', 'ekwa-wsei' ), $imp['source_site'] ) );
				}
				?>
			</p>
			<?php if ( ! empty( $imp['stories'] ) ) : ?>
				<ul class="ekwa-wsei-imported-list">
					<?php foreach ( $imp['stories'] as $s ) : ?>
						<li>
							<?php echo esc_html( $s['title'] ? $s['title'] : __( '(no title)', 'ekwa-wsei' ) ); ?>
							<?php if ( ! empty( $s['edit_link'] ) ) : ?>
								&mdash; <a href="<?php echo esc_url( $s['edit_link'] ); ?>"><?php esc_html_e( 'Edit', 'ekwa-wsei' ); ?></a>
							<?php endif; ?>
							<?php if ( ! empty( $s['view_link'] ) ) : ?>
								| <a href="<?php echo esc_url( $s['view_link'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View', 'ekwa-wsei' ); ?></a>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<?php if ( ! empty( $imp['warnings'] ) ) : ?>
				<details class="ekwa-wsei-warnings">
					<summary><?php printf( esc_html__( '%d warning(s)', 'ekwa-wsei' ), (int) count( $imp['warnings'] ) ); ?></summary>
					<ul>
						<?php foreach ( $imp['warnings'] as $w ) : ?>
							<li><?php echo esc_html( $w ); ?></li>
						<?php endforeach; ?>
					</ul>
				</details>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<h2 class="nav-tab-wrapper">
		<a href="<?php echo esc_url( $export_url ); ?>" class="nav-tab <?php echo 'export' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Export', 'ekwa-wsei' ); ?></a>
		<a href="<?php echo esc_url( $import_url ); ?>" class="nav-tab <?php echo 'import' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Import', 'ekwa-wsei' ); ?></a>
	</h2>

	<?php if ( 'export' === $active_tab ) : ?>
		<div class="ekwa-wsei-panel">
			<?php if ( empty( $stories ) ) : ?>
				<p><?php esc_html_e( 'No Web Stories were found on this site.', 'ekwa-wsei' ); ?></p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="ekwa-wsei-export-form">
					<input type="hidden" name="action" value="ekwa_wsei_export" />
					<?php wp_nonce_field( Plugin::NONCE_EXPORT ); ?>

					<p>
						<label><input type="checkbox" id="ekwa-wsei-select-all" /> <strong><?php esc_html_e( 'Select all', 'ekwa-wsei' ); ?></strong></label>
						<span class="description"><?php printf( esc_html__( '%d stories available.', 'ekwa-wsei' ), (int) count( $stories ) ); ?></span>
					</p>

					<p class="ekwa-wsei-batch-control">
						<label for="ekwa-wsei-batch-size"><strong><?php esc_html_e( 'Stories per ZIP (batch size):', 'ekwa-wsei' ); ?></strong></label>
						<input type="number" id="ekwa-wsei-batch-size" min="1" step="1" value="2" class="small-text" />
						<span class="description"><?php esc_html_e( 'Large libraries can time out as one big ZIP. Smaller batches build reliably and stay small enough to upload on import. Use 1 if you have very large videos.', 'ekwa-wsei' ); ?></span>
					</p>

					<table class="widefat striped ekwa-wsei-table">
						<thead>
							<tr>
								<td class="check-column"></td>
								<th><?php esc_html_e( 'Title', 'ekwa-wsei' ); ?></th>
								<th><?php esc_html_e( 'Status', 'ekwa-wsei' ); ?></th>
								<th><?php esc_html_e( 'Last modified', 'ekwa-wsei' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $stories as $story ) : ?>
								<tr>
									<th class="check-column">
										<input type="checkbox" class="ekwa-wsei-story" name="story_ids[]" value="<?php echo esc_attr( $story['id'] ); ?>" />
									</th>
									<td><strong><?php echo esc_html( $story['title'] ); ?></strong> <span class="ekwa-wsei-id">#<?php echo (int) $story['id']; ?></span></td>
									<td><?php echo esc_html( $story['status'] ); ?></td>
									<td><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $story['modified'] ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>

					<p class="submit">
						<button type="submit" class="button button-primary" id="ekwa-wsei-export-btn"><?php esc_html_e( 'Export selected (in batches)', 'ekwa-wsei' ); ?></button>
						<button type="submit" name="export_all" value="1" class="button" id="ekwa-wsei-export-all-btn"><?php esc_html_e( 'Export ALL stories', 'ekwa-wsei' ); ?></button>
						<span class="spinner ekwa-wsei-spinner"></span>
					</p>
				</form>

				<div id="ekwa-wsei-export-progress" class="ekwa-wsei-progress" hidden>
					<p class="ekwa-wsei-progress-status"></p>
					<ul id="ekwa-wsei-export-downloads" class="ekwa-wsei-downloads"></ul>
					<p>
						<button type="button" class="button" id="ekwa-wsei-download-all" hidden><?php esc_html_e( 'Re-download all batches', 'ekwa-wsei' ); ?></button>
					</p>
					<p class="description"><?php esc_html_e( 'Each batch downloads automatically as soon as it is built (one at a time, so transfers do not collide). Keep this tab open until all batches finish. If a download looks incomplete or your browser blocked it, just click its link above again — each ZIP stays on the server for ~2 hours. Then import all the ZIPs together on the Import tab of the new site.', 'ekwa-wsei' ); ?></p>
				</div>
			<?php endif; ?>
		</div>
	<?php else : ?>
		<div class="ekwa-wsei-panel">
			<p><?php esc_html_e( 'Upload one or more bundle ZIPs exported from another site (select all your batches at once). Assets are added to this site\'s Media Library, shared assets are reused across batches, and every link inside the stories is rewritten to point at the new copies.', 'ekwa-wsei' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
				<input type="hidden" name="action" value="ekwa_wsei_import" />
				<?php wp_nonce_field( Plugin::NONCE_IMPORT ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ekwa_wsei_bundle"><?php esc_html_e( 'Bundle ZIP(s)', 'ekwa-wsei' ); ?></label></th>
						<td>
							<input type="file" name="ekwa_wsei_bundle[]" id="ekwa_wsei_bundle" accept=".zip" multiple />
							<p class="description">
								<?php
								printf(
									/* translators: %s: max upload size */
									esc_html__( 'You can select multiple ZIPs at once. Maximum TOTAL upload size on this server: %s. For larger bundles, upload the ZIP via FTP/SFTP and use the server path field below.', 'ekwa-wsei' ),
									esc_html( size_format( wp_max_upload_size() ) )
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="server_path"><?php esc_html_e( 'or Server path', 'ekwa-wsei' ); ?></label></th>
						<td>
							<input type="text" name="server_path" id="server_path" class="regular-text code" placeholder="<?php echo esc_attr( wp_normalize_path( WP_CONTENT_DIR ) . '/uploads/my-bundle.zip' ); ?>" />
							<p class="description"><?php esc_html_e( 'Absolute path to a ZIP already on this server. Leave blank if you used the upload field.', 'ekwa-wsei' ); ?></p>
						</td>
					</tr>
				</table>

				<p class="submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Import bundle', 'ekwa-wsei' ); ?></button>
				</p>
			</form>
		</div>
	<?php endif; ?>
</div>
