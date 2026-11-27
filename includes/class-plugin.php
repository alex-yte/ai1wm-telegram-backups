<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Связывает компоненты плагина с событиями WordPress и управляет его жизненным циклом.
 */
class Ai1wm_Tg_Plugin {

	/**
	 * Регистрирует обработчики расписания, экспорта и административного интерфейса.
	 */
	public static function init() {
		add_filter( 'cron_schedules', array( 'Ai1wm_Tg_Cron', 'register_schedules' ) );
		add_action( AI1WM_TG_CRON_HOOK, array( 'Ai1wm_Tg_Backup', 'run' ) );
		add_action( 'ai1wm_status_export_done', array( 'Ai1wm_Tg_Backup', 'on_done' ) );
		add_action( 'ai1wm_status_export_error', array( 'Ai1wm_Tg_Backup', 'on_error' ), 10, 2 );
		add_action( 'admin_init', array( 'Ai1wm_Tg_Cron', 'ensure_scheduled' ) );

		if ( is_admin() ) {
			Ai1wm_Tg_Admin::register();
		}
	}

	/**
	 * Создаёт настройки по умолчанию и подготавливает расписание при активации.
	 */
	public static function activate() {
		add_filter( 'cron_schedules', array( 'Ai1wm_Tg_Cron', 'register_schedules' ) );

		if ( ! get_option( AI1WM_TG_OPTION ) ) {
			update_option( AI1WM_TG_OPTION, Ai1wm_Tg_Settings::defaults(), false );
		}

		Ai1wm_Tg_Cron::ensure_scheduled();
	}

	/**
	 * Удаляет запланированные задачи при деактивации плагина.
	 */
	public static function deactivate() {
		Ai1wm_Tg_Cron::clear();
	}
}
