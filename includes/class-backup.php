<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Запускает экспорт сайта, обновляет его статус и управляет архивами бэкапов.
 */
class Ai1wm_Tg_Backup {

	const ARCHIVE_SUFFIX = '-tg.wpress';

	/**
	 * Проверяет наличие активного All-in-One WP Migration и нужных функций экспорта.
	 */
	public static function dependencies_ok() {
		return defined( 'AI1WM_PLUGIN_NAME' )
			&& class_exists( 'Ai1wm_Export_Controller' )
			&& function_exists( 'ai1wm_archive_file' );
	}

	/**
	 * Проверяет, активировано ли расширение Unlimited или другой вариант Unlimited.
	 */
	public static function unlimited_ok() {
		return defined( 'AI1WMUE_PLUGIN_NAME' ) || defined( 'AI1WMVE_PLUGIN_NAME' );
	}

	/**
	 * Запускает экспорт сайта по настройкам и отправляет уведомление о старте.
	 *
	 * @param bool $force Запустить бэкап вручную, даже если расписание отключено.
	 */
	public static function run( $force = false ) {
		if ( ! self::dependencies_ok() ) {
			self::fail( 'All-in-One WP Migration не активен.' );
			return;
		}

		$settings = Ai1wm_Tg_Settings::get();
		if ( ! $force && empty( $settings['enabled'] ) ) {
			return;
		}

		if ( ! defined( 'AI1WM_SECRET_KEY' ) ) {
			self::fail( 'Не найден секретный ключ All-in-One WP Migration.' );
			return;
		}

		$backup_options = isset( $settings['backup_options'] ) && is_array( $settings['backup_options'] )
			? $settings['backup_options']
			: array();
		$options = array();

		if ( ! empty( $backup_options['themes_exclude'] ) ) {
			$options['no_themes'] = true;
		}

		if ( ! empty( $backup_options['plugins_exclude'] ) ) {
			$options['no_plugins'] = true;
		}

		if ( ! empty( $backup_options['uploads_exclude'] ) ) {
			$options['no_media'] = true;
		}

		$params = array(
			'ai1wm_tg_backup' => 1,
			'secret_key'      => get_option( AI1WM_SECRET_KEY ),
			'file'            => 1,
			'options'         => $options,
			'archive'         => self::archive_name(),
		);

		Ai1wm_Tg_Settings::update_state(
			array(
				'last_status'  => 'running',
				'last_message' => 'Бэкап запущен',
				'last_file'    => $params['archive'],
				'last_size'    => '',
			)
		);

		if ( ! empty( $settings['notify_start'] ) ) {
			Ai1wm_Tg_Telegram::send(
				self::format_message(
					'⏳ Бэкап запущен',
					array(
						'Сайт'   => home_url( '/' ),
						'Файл'   => $params['archive'],
						'Дата'   => wp_date( 'd.m.Y H:i' ),
					)
				)
			);
		}

		try {
			Ai1wm_Export_Controller::export( $params );
		} catch ( Exception $e ) {
			self::fail( $e->getMessage(), $params );
		}
	}

	/**
	 * Обрабатывает успешное завершение экспорта, хранение архивов и уведомления.
	 *
	 * @param array<string, mixed> $params
	 */
	public static function on_done( $params ) {
		if ( empty( $params['ai1wm_tg_backup'] ) ) {
			return;
		}

		$settings = Ai1wm_Tg_Settings::get();
		$file     = isset( $params['archive'] ) ? $params['archive'] : '';
		$size     = '';

		if ( function_exists( 'ai1wm_backup_size' ) ) {
			$size = ai1wm_backup_size( $params );
		}

		if ( ! $size && function_exists( 'ai1wm_archive_size' ) ) {
			$size = ai1wm_archive_size( $params );
		}

		Ai1wm_Tg_Settings::update_state(
			array(
				'last_status'  => 'success',
				'last_message' => 'Бэкап создан',
				'last_file'    => $file,
				'last_size'    => $size,
			)
		);

		self::apply_retention( (int) $settings['keep_backups'] );
		self::notify_backup_storage();

		$text = self::format_message(
			'✅ Бэкап создан',
			array(
				'Сайт'   => home_url( '/' ),
				'Файл'   => $file,
				'Размер' => $size ? $size : 'н/д',
				'Дата'   => wp_date( 'd.m.Y H:i' ),
				'Скачать' => home_url( '/wp-content/ai1wm-backups/' . $file ),
			)
		);

		if ( empty( $settings['notify_success'] ) ) {
			return;
		}

		Ai1wm_Tg_Telegram::send( $text );
	}

	/**
	 * Обрабатывает сигнал об ошибке экспорта, относящийся к бэкапу этого плагина.
	 *
	 * @param array<string, mixed> $params
	 * @param Exception            $exception
	 */
	public static function on_error( $params, $exception ) {
		if ( empty( $params['ai1wm_tg_backup'] ) ) {
			return;
		}

		self::fail( $exception ? $exception->getMessage() : 'Неизвестная ошибка экспорта', $params );
	}

	/**
	 * Сохраняет ошибочный статус бэкапа и при необходимости уведомляет Telegram.
	 *
	 * @param string               $message
	 * @param array<string, mixed> $params
	 */
	public static function fail( $message, $params = array() ) {
		$settings = Ai1wm_Tg_Settings::get();
		$file     = isset( $params['archive'] ) ? $params['archive'] : '';

		Ai1wm_Tg_Settings::update_state(
			array(
				'last_status'  => 'failed',
				'last_message' => $message,
				'last_file'    => $file,
			)
		);

		if ( empty( $settings['notify_error'] ) ) {
			return;
		}

		Ai1wm_Tg_Telegram::send(
			self::format_message(
				'❌ Бэкап не удался',
				array(
					'Сайт'    => home_url( '/' ),
					'Файл'    => $file,
					'Ошибка'  => $message,
					'Дата'    => wp_date( 'd.m.Y H:i' ),
				)
			)
		);
	}

	/**
	 * Создаёт имя архива AI1WM с отличительным суффиксом этого плагина.
	 */
	public static function archive_name() {
		$name = ai1wm_archive_file();

		return str_replace( '.wpress', self::ARCHIVE_SUFFIX, $name );
	}

	/**
	 * Оставляет указанное число самых новых архивов и удаляет остальные архивы плагина.
	 *
	 * @param int $keep
	 */
	public static function apply_retention( $keep ) {
		if ( $keep <= 0 || ! defined( 'AI1WM_BACKUPS_PATH' ) || ! is_dir( AI1WM_BACKUPS_PATH ) ) {
			return;
		}

		$files = glob( AI1WM_BACKUPS_PATH . DIRECTORY_SEPARATOR . '*' . self::ARCHIVE_SUFFIX );
		if ( ! is_array( $files ) || count( $files ) <= $keep ) {
			return;
		}

		usort(
			$files,
			static function ( $a, $b ) {
				// Сортирует архивы от самого нового к самому старому.
				return filemtime( $b ) <=> filemtime( $a );
			}
		);

		foreach ( array_slice( $files, $keep ) as $old ) {
			if ( is_file( $old ) ) {
				@unlink( $old );
			}
		}
	}

	/**
	 * Уведомляет Telegram, если в каталоге AI1WM хранится не менее пяти архивов.
	 */
	protected static function notify_backup_storage() {
		if ( ! defined( 'AI1WM_BACKUPS_PATH' ) || ! is_dir( AI1WM_BACKUPS_PATH ) ) {
			return;
		}

		$files = glob( AI1WM_BACKUPS_PATH . DIRECTORY_SEPARATOR . '*.wpress' );
		if ( ! is_array( $files ) ) {
			return;
		}

		$archives      = array_filter( $files, 'is_file' );
		$archive_count = count( $archives );
		if ( $archive_count < 5 ) {
			return;
		}

		$total_size       = 0;
		$unreadable_files = 0;
		foreach ( $archives as $archive ) {
			$size = filesize( $archive );
			if ( $size === false ) {
				$unreadable_files++;
				continue;
			}

			$total_size += $size;
		}

		$rows = array(
			'Количество архивов' => (string) $archive_count,
			'Общий размер'       => size_format( $total_size, 2 ),
			'Папка'              => AI1WM_BACKUPS_PATH,
		);

		if ( $unreadable_files > 0 ) {
			$rows['Не удалось определить размер файлов'] = (string) $unreadable_files;
		}

		Ai1wm_Tg_Telegram::send(
			self::format_message(
				'⚠️ В хранилище накопилось много бэкапов',
				$rows
			)
		);
	}

	/**
	 * Формирует HTML-сообщение Telegram из заголовка и набора подписанных значений.
	 *
	 * @param string                $title
	 * @param array<string, string> $rows
	 * @return string
	 */
	public static function format_message( $title, $rows ) {
		$lines = array( '<b>' . esc_html( $title ) . '</b>' );

		foreach ( $rows as $label => $value ) {
			if ( $value === '' || $value === null ) {
				continue;
			}

			$lines[] = '<b>' . esc_html( $label ) . ':</b> ' . esc_html( (string) $value );
		}

		return implode( "\n", $lines );
	}
}
