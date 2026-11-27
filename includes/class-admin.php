<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Регистрирует страницу настроек и обрабатывает действия администратора.
 */
class Ai1wm_Tg_Admin {

	/**
	 * Подключает обработчики меню, POST-запросов и уведомлений в админ-панели.
	 */
	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_post' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
	}

	/**
	 * Добавляет страницу настроек плагина в меню «Настройки» WordPress.
	 */
	public static function menu() {
		add_options_page(
			'AI1WM Telegram Backups',
			'AI1WM Telegram',
			'manage_options',
			'ai1wm-telegram-backups',
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Показывает предупреждения о недостающих зависимостях плагина.
	 */
	public static function notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! Ai1wm_Tg_Backup::dependencies_ok() ) {
			echo '<div class="notice notice-error"><p>AI1WM Telegram Backups: активируйте плагин <strong>All-in-One WP Migration</strong>.</p></div>';
			return;
		}

		if ( ! Ai1wm_Tg_Backup::unlimited_ok() ) {
			echo '<div class="notice notice-warning"><p>AI1WM Telegram Backups: расширение Unlimited не активно — лимит размера бэкапа бесплатной версии останется в силе.</p></div>';
		}
	}

	/**
	 * Проверяет права и nonce, затем выполняет выбранное действие из формы настроек.
	 */
	public static function handle_post() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( empty( $_POST['ai1wm_tg_action'] ) ) {
			return;
		}

		check_admin_referer( 'ai1wm_tg_settings' );

		$action = sanitize_key( wp_unslash( $_POST['ai1wm_tg_action'] ) );

		if ( in_array( $action, array( 'save', 'test', 'detect' ), true ) ) {
			Ai1wm_Tg_Settings::save( wp_unslash( $_POST ) );
			Ai1wm_Tg_Cron::ensure_scheduled();
		}

		if ( $action === 'save' ) {
			self::redirect( 'saved' );
		}

		if ( $action === 'detect' ) {
			$result = Ai1wm_Tg_Telegram::discover_chats();
			if ( is_wp_error( $result ) ) {
				self::redirect( 'detect_fail', $result->get_error_message() );
			}

			set_transient( 'ai1wm_tg_detected_chats_' . get_current_user_id(), $result, 10 * MINUTE_IN_SECONDS );

			if ( count( $result ) === 1 ) {
				Ai1wm_Tg_Settings::save( array_merge( wp_unslash( $_POST ), array( 'chat_id' => $result[0]['id'] ) ) );
				self::redirect( 'detect_one', $result[0]['id'] );
			}

			self::redirect( empty( $result ) ? 'detect_empty' : 'detect_ok' );
		}

		if ( $action === 'test' ) {
			$result = Ai1wm_Tg_Telegram::send(
				Ai1wm_Tg_Backup::format_message(
					'🔔 Тестовое сообщение',
					array(
						'Сайт' => home_url( '/' ),
						'Дата' => wp_date( 'd.m.Y H:i' ),
					)
				)
			);

			self::redirect( is_wp_error( $result ) ? 'test_fail' : 'test_ok', is_wp_error( $result ) ? $result->get_error_message() : '' );
		}

		if ( $action === 'run' ) {
			if ( ! Ai1wm_Tg_Backup::dependencies_ok() ) {
				self::redirect( 'run_fail', 'All-in-One WP Migration не активен.' );
			}

			Ai1wm_Tg_Settings::save( wp_unslash( $_POST ) );
			Ai1wm_Tg_Cron::ensure_scheduled();

			if ( ! Ai1wm_Tg_Cron::spawn_now() ) {
				self::redirect( 'run_fail', 'Не удалось поставить ручной запуск бэкапа в очередь.' );
			}

			self::redirect( 'run_queued' );
		}
	}

	/**
	 * Перенаправляет обратно на страницу настроек с кодом результата и сообщением.
	 *
	 * @param string $status
	 * @param string $message
	 */
	protected static function redirect( $status, $message = '' ) {
		$url = add_query_arg(
			array(
				'page'           => 'ai1wm-telegram-backups',
				'ai1wm_tg_flash' => $status,
				'ai1wm_tg_msg'   => $message ? rawurlencode( $message ) : false,
			),
			admin_url( 'options-general.php' )
		);

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Отображает страницу настроек, текущее состояние бэкапа и форму управления.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = Ai1wm_Tg_Settings::get();
		$state    = Ai1wm_Tg_Settings::state();
		$next     = Ai1wm_Tg_Cron::next_run();
		$flash    = isset( $_GET['ai1wm_tg_flash'] ) ? sanitize_key( wp_unslash( $_GET['ai1wm_tg_flash'] ) ) : '';
		$msg      = isset( $_GET['ai1wm_tg_msg'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['ai1wm_tg_msg'] ) ) ) : '';
		$token    = $settings['bot_token'] ? '********' : '';
		$chats    = get_transient( 'ai1wm_tg_detected_chats_' . get_current_user_id() );
		if ( ! is_array( $chats ) ) {
			$chats = array();
		}

		?>
		<div class="wrap">
			<h1>AI1WM Telegram Backups</h1>

			<?php if ( $flash === 'saved' ) : ?>
				<div class="notice notice-success is-dismissible"><p>Настройки сохранены.</p></div>
			<?php elseif ( $flash === 'test_ok' ) : ?>
				<div class="notice notice-success is-dismissible"><p>Тестовое сообщение отправлено в Telegram.</p></div>
			<?php elseif ( $flash === 'test_fail' ) : ?>
				<div class="notice notice-error is-dismissible"><p>Не удалось отправить тест: <?php echo esc_html( $msg ); ?></p></div>
			<?php elseif ( $flash === 'detect_empty' ) : ?>
				<div class="notice notice-warning is-dismissible"><p>Бот пока не видит ни одного чата. Откройте его в Telegram, нажмите Start / напишите любое сообщение, затем снова «Найти Chat ID».</p></div>
			<?php elseif ( $flash === 'detect_one' ) : ?>
				<div class="notice notice-success is-dismissible"><p>Найден чат, Chat ID подставлен: <?php echo esc_html( $msg ); ?>. Теперь отправьте тест.</p></div>
			<?php elseif ( $flash === 'detect_ok' ) : ?>
				<div class="notice notice-success is-dismissible"><p>Найдены чаты ниже — скопируйте нужный ID в поле Chat ID и сохраните.</p></div>
			<?php elseif ( $flash === 'detect_fail' ) : ?>
				<div class="notice notice-error is-dismissible"><p>Не удалось получить чаты: <?php echo esc_html( $msg ); ?></p></div>
			<?php elseif ( $flash === 'run_queued' ) : ?>
				<div class="notice notice-success is-dismissible"><p>Ручной запуск бэкапа поставлен в очередь. Автоматическое расписание при этом не меняется.</p></div>
			<?php elseif ( $flash === 'run_fail' ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php echo esc_html( $msg ); ?></p></div>
			<?php endif; ?>

			<div class="card" style="max-width: 760px; padding: 12px 16px;">
				<p><strong>Последний статус:</strong>
					<?php echo $state['last_status'] ? esc_html( $state['last_status'] ) : 'ещё не запускался'; ?>
				</p>
				<?php if ( $state['last_at'] ) : ?>
					<p><strong>Когда:</strong> <?php echo esc_html( wp_date( 'd.m.Y H:i', $state['last_at'] ) ); ?></p>
				<?php endif; ?>
				<?php if ( $state['last_file'] ) : ?>
					<p><strong>Файл:</strong> <?php echo esc_html( $state['last_file'] ); ?></p>
				<?php endif; ?>
				<?php if ( $state['last_size'] ) : ?>
					<p><strong>Размер:</strong> <?php echo esc_html( $state['last_size'] ); ?></p>
				<?php endif; ?>
				<?php if ( $state['last_message'] ) : ?>
					<p><strong>Сообщение:</strong> <?php echo esc_html( $state['last_message'] ); ?></p>
				<?php endif; ?>
				<p><strong>Следующий запуск:</strong>
					<?php echo $next ? esc_html( wp_date( 'd.m.Y H:i', $next ) ) : 'не запланирован'; ?>
				</p>
			</div>

			<form method="post" action="">
				<?php wp_nonce_field( 'ai1wm_tg_settings' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Расписание</th>
						<td>
							<label>
								<input type="checkbox" name="enabled" value="1" <?php checked( $settings['enabled'] ); ?>>
								Создавать бэкапы автоматически
							</label>
							<p>
								<label for="ai1wm-tg-interval">Интервал:</label>
								<select name="interval_days" id="ai1wm-tg-interval">
									<option value="4" <?php selected( $settings['interval_days'], 4 ); ?>>каждые 4 дня</option>
									<option value="5" <?php selected( $settings['interval_days'], 5 ); ?>>каждые 5 дней</option>
                                    <option value="7" <?php selected( $settings['interval_days'], 7 ); ?>>каждые 7 дней</option>
                                    <option value="14" <?php selected( $settings['interval_days'], 14 ); ?>>каждые 14 дней</option>
                                    <option value="30" <?php selected( $settings['interval_days'], 30 ); ?>>каждые 30 дней</option>
								</select>
							</p>
							<p class="description">
								WP-Cron срабатывает при посещении сайта. Для продакшена лучше добавить системный cron:
								<code>wget -q -O - <?php echo esc_html( site_url( 'wp-cron.php' ) ); ?> >/dev/null 2>&amp;1</code>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ai1wm-tg-token">Telegram Bot Token</label></th>
						<td>
							<input type="password" class="regular-text" id="ai1wm-tg-token" name="bot_token" value="<?php echo esc_attr( $token ); ?>" autocomplete="off" <?php disabled( Ai1wm_Tg_Settings::token_locked() ); ?>>
							<p class="description">Создайте бота через @BotFather. Можно задать константой <code>AI1WM_TG_BOT_TOKEN</code> в wp-config.php.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ai1wm-tg-chat">Chat ID</label></th>
						<td>
							<input type="text" class="regular-text" id="ai1wm-tg-chat" name="chat_id" value="<?php echo esc_attr( $settings['chat_id'] ); ?>" <?php disabled( Ai1wm_Tg_Settings::chat_locked() ); ?>>
							<p>
								<button type="submit" class="button" name="ai1wm_tg_action" value="detect">Найти Chat ID</button>
							</p>
							<?php if ( $chats ) : ?>
								<ul>
									<?php foreach ( $chats as $chat ) : ?>
										<li>
											<code><?php echo esc_html( $chat['id'] ); ?></code>
											— <?php echo esc_html( $chat['title'] ); ?>
											(<?php echo esc_html( $chat['type'] ); ?>)
										</li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>
							<p class="description">
								Ошибка <code>chat not found</code> значит, что бот не знает этот чат.
								Напишите боту <code>/start</code>, затем нажмите «Найти Chat ID».
								ID из @userinfobot подходит только если вы уже открыли переписку именно с этим ботом.
								Для группы: добавьте бота и напишите в группе сообщение.
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ai1wm-tg-keep">Сколько бэкапов хранить</label></th>
						<td>
							<input type="number" min="0" step="1" id="ai1wm-tg-keep" name="keep_backups" value="<?php echo esc_attr( $settings['keep_backups'] ); ?>">
							<p class="description">Удаляются только файлы этого плагина (<code>*-tg.wpress</code>). 0 — не удалять.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Параметры бекапа</th>
						<td>
							<input type="checkbox" name="themes_exclude" id="ai1wm-tg-themes" value="1" <?php checked( $settings['backup_options']['themes_exclude'] ); ?>><label for="ai1wm-tg-themes"> исключать темы</label><br>
                            <input type="checkbox" name="plugins_exclude" id="ai1wm-tg-plugins" value="1" <?php checked( $settings['backup_options']['plugins_exclude'] ); ?>><label for="ai1wm-tg-plugins"> исключать плагины</label><br>
                            <input type="checkbox" name="uploads_exclude" id="ai1wm-tg-uploads" value="1" <?php checked( $settings['backup_options']['uploads_exclude'] ); ?>><label for="ai1wm-tg-uploads"> исключать медиафайлы</label>
						</td>
					</tr>
					<tr>
						<th scope="row">Уведомления</th>
						<td>
							<label><input type="checkbox" name="notify_start" value="1" <?php checked( $settings['notify_start'] ); ?>> старт бэкапа</label><br>
							<label><input type="checkbox" name="notify_success" value="1" <?php checked( $settings['notify_success'] ); ?>> успешное завершение</label><br>
							<label><input type="checkbox" name="notify_error" value="1" <?php checked( $settings['notify_error'] ); ?>> ошибка</label><br>
							<p class="description">Плагин отправляет в Telegram текстовые уведомления о бэкапах и ошибках.</p>
						</td>
					</tr>
				</table>

				<p class="submit">
					<button type="submit" class="button button-primary" name="ai1wm_tg_action" value="save">Сохранить</button>
					<button type="submit" class="button" name="ai1wm_tg_action" value="test">Отправить тест в Telegram</button>
					<button type="submit" class="button button-secondary" name="ai1wm_tg_action" value="run" onclick="return confirm('Запустить бэкап сейчас?');">Сделать бэкап сейчас</button>
				</p>
			</form>
		</div>
		<?php
	}
}
