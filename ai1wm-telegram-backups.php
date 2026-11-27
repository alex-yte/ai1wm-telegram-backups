<?php
/**
 * Plugin Name: AI1WM Telegram Backups
 * Description: Планировщик бэкапов All-in-One WP Migration с уведомлениями в Telegram.
 * Version: 1.1.0
 * Requires at least: 5.3
 * Author: Raev Nord Studio
 * Author URI: https://den-dev.vercel.app
 * Text Domain: ai1wm-telegram-backups
 * License: GPLv2 or later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AI1WM_TG_VERSION', '1.1.0' );
define( 'AI1WM_TG_FILE', __FILE__ );
define( 'AI1WM_TG_PATH', plugin_dir_path( __FILE__ ) );
define( 'AI1WM_TG_URL', plugin_dir_url( __FILE__ ) );
define( 'AI1WM_TG_CRON_HOOK', 'ai1wm_tg_run_backup' );
define( 'AI1WM_TG_OPTION', 'ai1wm_tg_settings' );
define( 'AI1WM_TG_STATE', 'ai1wm_tg_state' );

require_once AI1WM_TG_PATH . 'includes/class-settings.php';
require_once AI1WM_TG_PATH . 'includes/class-telegram.php';
require_once AI1WM_TG_PATH . 'includes/class-backup.php';
require_once AI1WM_TG_PATH . 'includes/class-cron.php';
require_once AI1WM_TG_PATH . 'includes/class-admin.php';
require_once AI1WM_TG_PATH . 'includes/class-plugin.php';

// Регистрирует обработчики активации и деактивации плагина в WordPress.
register_activation_hook( __FILE__, array( 'Ai1wm_Tg_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Ai1wm_Tg_Plugin', 'deactivate' ) );

// Запускает подключение компонентов плагина после загрузки WordPress.
add_action( 'plugins_loaded', array( 'Ai1wm_Tg_Plugin', 'init' ) );
