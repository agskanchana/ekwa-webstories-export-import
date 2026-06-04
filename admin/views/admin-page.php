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
							_n( '%d asset was already in the Media Library from an earlier import and was reused (not duplicated).', '%d assets were already in the Media Library from earlier imports and were reused (not duplicated).', (int) $imp['assets_reused'], 'ekwa-wsei' ),
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

					<p class="description ekwa-wsei-single-note">
						<?php esc_html_e( 'All selected stories are exported into a single ZIP. The file is built on the server (media is streamed into it, so memory stays low no matter how many stories), then downloaded automatically.', 'ekwa-wsei' ); ?>
						<br />
						<?php esc_html_e( 'For a very large library this one request can still hit your server\'s time limit (a 500 error). If that happens, export fewer stories at a time, or use "Export this story" per row.', 'ekwa-wsei' ); ?>
					</p>

					<table class="widefat striped ekwa-wsei-table">
						<thead>
							<tr>
								<td class="check-column"></td>
								<th><?php esc_html_e( 'Title', 'ekwa-wsei' ); ?></th>
								<th><?php esc_html_e( 'Status', 'ekwa-wsei' ); ?></th>
								<th><?php esc_html_e( 'Last modified', 'ekwa-wsei' ); ?></th>
								<th><?php esc_html_e( 'Export', 'ekwa-wsei' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $stories as $story ) : ?>
								<?php
								$one_url = wp_nonce_url(
									admin_url( 'admin-post.php?action=ekwa_wsei_export_one&story=' . (int) $story['id'] ),
									'ekwa_wsei_export_one_' . (int) $story['id']
								);
								?>
								<tr>
									<th class="check-column">
										<input type="checkbox" class="ekwa-wsei-story" name="story_ids[]" value="<?php echo esc_attr( $story['id'] ); ?>" />
									</th>
									<td><strong><?php echo esc_html( $story['title'] ); ?></strong> <span class="ekwa-wsei-id">#<?php echo (int) $story['id']; ?></span></td>
									<td><?php echo esc_html( $story['status'] ); ?></td>
									<td><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $story['modified'] ) ); ?></td>
									<td><a class="button button-small" href="<?php echo esc_url( $one_url ); ?>"><?php esc_html_e( 'Export this story', 'ekwa-wsei' ); ?></a></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>

					<p class="description"><?php esc_html_e( 'To export one post at a time, click "Export this story" on any row above — it downloads just that single story as a ZIP (the smallest, most reliable request, and the best option if a larger export gives a 500 error). Or tick several stories and use the buttons below to build one combined ZIP.', 'ekwa-wsei' ); ?></p>

					<p class="submit">
						<button type="submit" class="button button-primary" id="ekwa-wsei-export-btn"><?php esc_html_e( 'Export selected stories', 'ekwa-wsei' ); ?></button>
						<button type="submit" name="export_all" value="1" class="button" id="ekwa-wsei-export-all-btn"><?php esc_html_e( 'Export ALL stories', 'ekwa-wsei' ); ?></button>
						<span class="spinner ekwa-wsei-spinner"></span>
					</p>
				</form>

				<div id="ekwa-wsei-export-progress" class="ekwa-wsei-progress" hidden>
					<p class="ekwa-wsei-progress-status"></p>
					<ul id="ekwa-wsei-export-downloads" class="ekwa-wsei-downloads"></ul>
					<p>
						<button type="button" class="button" id="ekwa-wsei-download-all" hidden><?php esc_html_e( 'Download the ZIP again', 'ekwa-wsei' ); ?></button>
					</p>
					<p class="description"><?php esc_html_e( 'Your selected stories are bundled into one ZIP, built on the server and then downloaded automatically. Keep this tab open until it finishes. If the download looks incomplete or your browser blocked it, just click its link above again — the ZIP stays on the server for ~2 hours. Then import it on the Import tab of the new site. Tip: a very large single ZIP may exceed the upload limit on the new site — if so, upload it via FTP/SFTP and use the Import tab\'s "Server path" field.', 'ekwa-wsei' ); ?></p>
				</div>
			<?php endif; ?>
		</div>
	<?php else : ?>
		<div class="ekwa-wsei-panel">
			<p><?php esc_html_e( 'Upload bundle ZIPs exported from another site. You can select all your batches at once, OR import them one at a time (repeat this step for each ZIP) — either way works. Assets are added to this site\'s Media Library, anything already imported from the same source is reused (never duplicated), and every link inside the stories is rewritten to point at the new copies.', 'ekwa-wsei' ); ?></p>

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
					<tr>
						<th scope="row"><?php esc_html_e( 'Memory', 'ekwa-wsei' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="low_memory" value="1" checked />
								<?php esc_html_e( 'Low-memory mode (recommended) — do not regenerate thumbnails', 'ekwa-wsei' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Keep this ON if importing has caused a "critical error". Thumbnail regeneration loads full images into memory and is the usual cause of out-of-memory crashes on shared hosting. With this ON the full-size images are used everywhere (stories still display correctly). Turn it OFF only if your server has plenty of memory and you want WordPress to regenerate every image size.', 'ekwa-wsei' ); ?></p>
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
