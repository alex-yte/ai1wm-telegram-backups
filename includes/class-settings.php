<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Загружает, сохраняет и нормализует настройки и состояние выполнения бэкапа.
 */
class Ai1wm_Tg_Settings {

	/**
	 * Возвращает исходные настройки плагина, используемые при первой установке.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults() {
		return array(
			'enabled'        => false,
			'interval_days'  => 4,
			'bot_token'      => '',
			'chat_id'        => '',
			'keep_backups'   => 3,
			'notify_start'   => true,
			'notify_success' => true,
			'notify_error'   => true,
            'backup_options' => array(
                'themes_exclude'  => false,
                'plugins_exclude' => false,
                'uploads_exclude' => false,
            ),
		);
	}

	/**
	 * Загружает настройки, дополняет их значениями по умолчанию и проверяет значения.
	 *
	 * @return array<string, mixed>
	 */
	public static function get() {
		$saved = get_option( AI1WM_TG_OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		$settings = array_merge( self::defaults(), $saved );

		if ( defined( 'AI1WM_TG_BOT_TOKEN' ) && AI1WM_TG_BOT_TOKEN ) {
			$settings['bot_token'] = AI1WM_TG_BOT_TOKEN;
		}

		if ( defined( 'AI1WM_TG_CHAT_ID' ) && AI1WM_TG_CHAT_ID ) {
			$settings['chat_id'] = AI1WM_TG_CHAT_ID;
		}

		$settings['interval_days'] = in_array( (int) $settings['interval_days'], array( 4, 5 , 7, 14, 30 ), true )
			? (int) $settings['interval_days']
			: 4;
		$settings['keep_backups']  = max( 0, (int) $settings['keep_backups'] );
		$settings['enabled']       = ! empty( $settings['enabled'] );

		return $settings;
	}

	/**
	 * Проверяет и сохраняет настройки, учитывая значения, заданные константами.
	 *
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public static function save( $input ) {
		$current = self::get();
		$clean   = self::defaults();

		$clean['enabled']        = ! empty( $input['enabled'] );
		$clean['interval_days']  = in_array( (int) $input['interval_days'], array( 4, 5, 7, 14, 30 ), true ) ? (int) $input['interval_days'] : 4;
		$clean['chat_id']        = isset( $input['chat_id'] ) ? self::normalize_chat_id( $input['chat_id'] ) : $current['chat_id'];
		$clean['keep_backups']   = isset( $input['keep_backups'] ) ? max( 0, (int) $input['keep_backups'] ) : 3;
		$clean['notify_start']   = ! empty( $input['notify_start'] );
		$clean['notify_success'] = ! empty( $input['notify_success'] );
		$clean['notify_error']   = ! empty( $input['notify_error'] );
        $clean['backup_options']  = array(
            'themes_exclude'  => ! empty( $input['themes_exclude'] ),
            'plugins_exclude' => ! empty( $input['plugins_exclude'] ),
            'uploads_exclude' => ! empty( $input['uploads_exclude'] ),
        );

		$token = isset( $input['bot_token'] ) ? trim( (string) $input['bot_token'] ) : '';
		if ( $token === '' || $token === '********' ) {
			$clean['bot_token'] = $current['bot_token'];
		} else {
			$clean['bot_token'] = sanitize_text_field( $token );
		}

		if ( defined( 'AI1WM_TG_BOT_TOKEN' ) && AI1WM_TG_BOT_TOKEN ) {
			$clean['bot_token'] = $current['bot_token'];
		}

		if ( defined( 'AI1WM_TG_CHAT_ID' ) && AI1WM_TG_CHAT_ID ) {
			$clean['chat_id'] = $current['chat_id'];
		}



		update_option( AI1WM_TG_OPTION, $clean, false );

		return $clean;
	}

	/**
	 * Возвращает сохранённое состояние бэкапа с полями по умолчанию.
	 *
	 * @return array<string, mixed>
	 */
	public static function state() {
		$state = get_option( AI1WM_TG_STATE, array() );
		if ( ! is_array( $state ) ) {
			$state = array();
		}

		return array_merge(
			array(
				'last_status'  => '',
				'last_message' => '',
				'last_file'    => '',
				'last_size'    => '',
				'last_at'      => 0,
			),
			$state
		);
	}

	/**
	 * Обновляет состояние бэкапа и записывает время последнего изменения.
	 *
	 * @param array<string, mixed> $data
	 */
	public static function update_state( $data ) {
		$state = array_merge( self::state(), $data );
		$state['last_at'] = time();
		update_option( AI1WM_TG_STATE, $state, false );
	}

	/**
	 * Проверяет, задан ли токен Telegram через константу и поэтому заблокирован.
	 */
	public static function token_locked() {
		return defined( 'AI1WM_TG_BOT_TOKEN' ) && AI1WM_TG_BOT_TOKEN;
	}

	/**
	 * Проверяет, задан ли Chat ID через константу и поэтому заблокирован.
	 */
	public static function chat_locked() {
		return defined( 'AI1WM_TG_CHAT_ID' ) && AI1WM_TG_CHAT_ID;
	}

	/**
	 * Удаляет пробелы и распространённые лишние символы из значения Chat ID.
	 *
	 * @param mixed $value
	 * @return string
	 */
	public static function normalize_chat_id( $value ) {
		$chat_id = trim( (string) $value );
		$chat_id = preg_replace( '/[\s\x{00A0}\x{200B}\x{FEFF}"\'`]/u', '', $chat_id );
		$chat_id = preg_replace( '/^chat_id=/i', '', $chat_id );

		return is_string( $chat_id ) ? $chat_id : '';
	}
}
