<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Отправляет текстовые сообщения в Telegram и получает доступные боту чаты.
 */
class Ai1wm_Tg_Telegram {

	/**
	 * Отправляет текстовое сообщение в заданный чат Telegram.
	 *
	 * @param string $text
	 * @return true|WP_Error
	 */
	public static function send( $text ) {
		$settings = Ai1wm_Tg_Settings::get();
		$token    = trim( (string) $settings['bot_token'] );
		$chat_id  = Ai1wm_Tg_Settings::normalize_chat_id( $settings['chat_id'] );

		if ( $token === '' || $chat_id === '' ) {
			return new WP_Error( 'ai1wm_tg_missing', 'Не заданы токен бота или Chat ID.' );
		}

		$response = self::api(
			$token,
			'sendMessage',
			array(
				'chat_id'    => $chat_id,
				'text'       => $text,
				'parse_mode' => 'HTML',
			)
		);

		if ( is_wp_error( $response ) ) {
			return self::explain( $response, $chat_id );
		}

		return true;
	}

	/**
	 * Получает обновления бота и собирает уникальные чаты, доступные для уведомлений.
	 *
	 * @return array<int, array<string, string>>|WP_Error
	 */
	public static function discover_chats() {
		$settings = Ai1wm_Tg_Settings::get();
		$token    = trim( (string) $settings['bot_token'] );

		if ( $token === '' ) {
			return new WP_Error( 'ai1wm_tg_missing', 'Сначала сохраните токен бота.' );
		}

		$response = self::api( $token, 'getUpdates', array( 'limit' => 100, 'timeout' => 0 ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$chats = array();
		$items = isset( $response['result'] ) && is_array( $response['result'] ) ? $response['result'] : array();

		foreach ( $items as $update ) {
			$candidates = array();

			if ( ! empty( $update['message']['chat'] ) ) {
				$candidates[] = $update['message']['chat'];
			}
			if ( ! empty( $update['my_chat_member']['chat'] ) ) {
				$candidates[] = $update['my_chat_member']['chat'];
			}
			if ( ! empty( $update['channel_post']['chat'] ) ) {
				$candidates[] = $update['channel_post']['chat'];
			}

			foreach ( $candidates as $chat ) {
				if ( empty( $chat['id'] ) ) {
					continue;
				}

				$id           = (string) $chat['id'];
				$chats[ $id ] = array(
					'id'    => $id,
					'type'  => isset( $chat['type'] ) ? (string) $chat['type'] : '',
					'title' => self::chat_title( $chat ),
				);
			}
		}

		return array_values( $chats );
	}

	/**
	 * Формирует отображаемое название чата из его названия, имени и username.
	 *
	 * @param array<string, mixed> $chat
	 * @return string
	 */
	protected static function chat_title( $chat ) {
		if ( ! empty( $chat['title'] ) ) {
			return (string) $chat['title'];
		}

		$parts = array_filter(
			array(
				isset( $chat['first_name'] ) ? $chat['first_name'] : '',
				isset( $chat['last_name'] ) ? $chat['last_name'] : '',
				isset( $chat['username'] ) ? '@' . $chat['username'] : '',
			)
		);

		return $parts ? implode( ' ', $parts ) : 'без имени';
	}

	/**
	 * Вызывает метод Telegram Bot API и возвращает разобранный ответ или ошибку.
	 *
	 * @param string               $token
	 * @param string               $method
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>|WP_Error
	 */
	protected static function api( $token, $method, $body = array() ) {
		$response = wp_remote_post(
			'https://api.telegram.org/bot' . rawurlencode( $token ) . '/' . $method,
			array(
				'timeout' => 20,
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$parsed = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $parsed ) ) {
			return new WP_Error( 'ai1wm_tg_api', 'Telegram вернул пустой ответ.' );
		}

		if ( empty( $parsed['ok'] ) ) {
			$message = ! empty( $parsed['description'] ) ? $parsed['description'] : 'Telegram API вернул ошибку.';
			return new WP_Error( 'ai1wm_tg_api', $message );
		}

		return $parsed;
	}

	/**
	 * Дополняет ошибку Telegram подсказкой для случая неизвестного Chat ID.
	 *
	 * @param WP_Error $error
	 * @param string   $chat_id
	 * @return WP_Error
	 */
	protected static function explain( $error, $chat_id ) {
		$message = $error->get_error_message();

		if ( stripos( $message, 'chat not found' ) !== false ) {
			$message .= sprintf(
				' Chat ID «%s» Telegram не знает. Напишите боту /start в личку (или добавьте его в группу) и нажмите «Найти Chat ID». Не используйте токен бота и ID из @userinfobot, если этому боту вы ещё не писали.',
				$chat_id
			);
		}

		return new WP_Error( $error->get_error_code(), $message );
	}
}
