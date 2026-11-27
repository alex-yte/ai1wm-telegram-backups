<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Управляет периодическими и немедленными задачами WP-Cron для бэкапов.
 */
class Ai1wm_Tg_Cron {

	/**
	 * Возвращает ключ расписания WP-Cron согласно выбранному интервалу.
	 */
	public static function schedule_key() {
		$days = (int) Ai1wm_Tg_Settings::get()['interval_days'];

		return 'ai1wm_tg_every_' . $days . '_days';
	}

	/**
	 * Регистрирует поддерживаемые интервалы запуска бэкапов в WP-Cron.
	 *
	 * @param array<string, array<string, mixed>> $schedules
	 * @return array<string, array<string, mixed>>
	 */
	public static function register_schedules( $schedules ) {
		$labels = array(
			4 => 'Каждые 4 дня',
			5 => 'Каждые 5 дней',
			7 => 'Каждые 7 дней',
			14 => 'Каждые 14 дней',
			30 => 'Каждые 30 дней',
		);

		foreach ( $labels as $days => $label ) {
			$key               = 'ai1wm_tg_every_' . $days . '_days';
			$schedules[ $key ] = array(
				'interval' => $days * DAY_IN_SECONDS,
				'display'  => $label,
			);
		}

		return $schedules;
	}

	/**
	 * Создаёт или обновляет расписание бэкапа в соответствии с настройками.
	 */
	public static function ensure_scheduled() {
		$settings = Ai1wm_Tg_Settings::get();
		$hook     = AI1WM_TG_CRON_HOOK;

		if ( empty( $settings['enabled'] ) ) {
			self::clear();
			return;
		}

		$wanted = self::schedule_key();
		$next   = wp_next_scheduled( $hook );

		if ( $next ) {
			$event = wp_get_scheduled_event( $hook );
			if ( $event && isset( $event->schedule ) && $event->schedule === $wanted ) {
				return;
			}

			self::clear();
		}

		wp_schedule_event( time() + HOUR_IN_SECONDS, $wanted, $hook );
	}

	/**
	 * Удаляет все запланированные события запуска бэкапа этого плагина.
	 */
	public static function clear() {
		$timestamp = wp_next_scheduled( AI1WM_TG_CRON_HOOK );
		while ( $timestamp ) {
			wp_unschedule_event( $timestamp, AI1WM_TG_CRON_HOOK );
			$timestamp = wp_next_scheduled( AI1WM_TG_CRON_HOOK );
		}

		wp_clear_scheduled_hook( AI1WM_TG_CRON_HOOK );
	}

	/**
	 * Возвращает время ближайшего запланированного запуска либо false.
	 */
	public static function next_run() {
		return wp_next_scheduled( AI1WM_TG_CRON_HOOK );
	}

	/**
	 * Ставит ручной принудительный запуск в очередь, не меняя постоянное расписание.
	 *
	 * @return bool Удалось ли поставить задачу в очередь.
	 */
	public static function spawn_now() {
		$args = array( true );

		if ( ! wp_next_scheduled( AI1WM_TG_CRON_HOOK, $args ) && ! wp_schedule_single_event( time(), AI1WM_TG_CRON_HOOK, $args ) ) {
			return false;
		}

		spawn_cron();

		return true;
	}
}
