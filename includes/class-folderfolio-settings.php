<?php
namespace FolderFolio;

defined( 'ABSPATH' ) || exit;

class Settings {
	private static $instance = null;
	private $pageId            = null;
	public $settingPageSuffix  = '';

	// Private constructor to prevent creating a new instance of the class via the `new` operator from outside of this class
	private function __construct() {
		// Initialize things here, similar to a constructor
		$this->init();
	}

	// Prevent cloning of the instance
	private function __clone() {}

	// Prevent unserialization of the instance
	private function __wakeup() {}

	// Method to retrieve or create the class instance
	public static function getInstance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function init() {
		add_filter( 'plugin_action_links_' . BZFF_PLUGIN_BASE_NAME, array( $this, 'addActionLinks' ) );
		add_filter( 'plugin_row_meta', array( $this, 'plugin_row_meta' ), 10, 2 );

		add_action( 'admin_init', array( $this, 'registerSettings' ) );
		add_action( 'admin_footer', array( $this, 'adminFooter' ) );
		add_action( 'admin_menu', array( $this, 'settingsMenu' ) );
	}

	public function settingsMenu() {
		$folderFolioIcon = '';

		add_menu_page( 'FolderFolio', 'FolderFolio', 'manage_options', 'folderfolio-settings', null, $folderFolioIcon );

		$this->settingPageSuffix = add_submenu_page( 'folderfolio-settings', 'FolderFolio Settings', 'FolderFolio Settings', 'manage_options', 'folderfolio-settings', array( $this, 'settingsPage' ), 0 );
		add_submenu_page(
			'folderfolio-settings',
			'',
			'<span>' . __( 'Go Pro', 'folderfolio' ) . '</span>',
			'manage_options',
			'go_folderfolio_pro',
			array( $this, 'goProRedirects' ),
			100
		);
	}

	public function adminFooter() {
		?>
		<style>
            body.admin-color-fresh #adminmenu #toplevel_page_folderfolio-settings a[href="admin.php?page=go_folderfolio_pro"] {
                color: #00BC28;
                font-weight: bold;
                position: relative;
            }

            body.admin-color-fresh #adminmenu #toplevel_page_folderfolio-settings a[href="admin.php?page=go_folderfolio_pro"]::after {
                content: '';
                position: absolute;
                width: 4px;
                top: 0;
                bottom: 0;
                left: 0;
                background: green;
            }
		</style>
		<script>
            jQuery(document).ready(function() {
                jQuery('#toplevel_page_folderfolio-settings a[href="admin.php?page=go_folderfolio_pro"]').click(function(event) {
                    event.preventDefault()
                    window.open('https://beezna.gr', '_blank')
                })
            })
		</script>
		<?php
	}

	public function goProRedirects() {
		if ( empty( $_GET['page'] ) ) {
			return;
		}

		if ( 'go_folderfolio_pro' === $_GET['page'] ) {
			?>
			<script>window.location.href = "https://beezna.gr"</script>
			<?php
		}
	}

	public function settingsPage() {
		echo '<h1>Settings Page</h1>';
	}

	public function plugin_row_meta( $links, $file ) {
		if ( strpos( $file, 'folderfolio.php' ) !== false ) {
			$new_links = array(
				'doc' => '<a href="https://beezna.gr" target="_blank">' . __( 'Documentation', 'folderfolio' ) . '</a>',
			);

			$links = array_merge( $links, $new_links );
		}

		return $links;
	}

	public function addActionLinks( $links ) {
		$settingsLinks = array(
			'<a href="' . admin_url( 'admin.php?page=' . $this->getPageId() ) . '">Settings</a>',
		);

		$links[] = '<a target="_blank" href="https://beezna.gr" style="color: #43B854; font-weight: bold">' . __( 'Go Pro', 'folderfolio' ) . '</a>';
		return array_merge( $settingsLinks, $links );
	}

	public function getPageId() {
		if ( null == $this->pageId ) {
			$this->pageId = BZFF_PREFIX . '-settings';
		}

		return $this->pageId;
	}

	public function registerSettings() {
		$settings = array(
			'njt_fbv_folder_per_user',
			'njt_fbv_default_folder',
		);
		foreach ( $settings as $k => $v ) {
			register_setting( 'njt_fbv', $v );
		}
	}
}
