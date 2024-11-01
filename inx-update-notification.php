<?php
/*
Plugin Name: Update Notification
Plugin URI: https://inexio.jp/inxresults/inx-update-notification/
Description: WordPressの記事やページを更新した際に、Discord、ChatWork、LINE、Slack、Telegram、Guilded、Google Chat に更新通知を送ることができます。
Version: 1.5.4
Author: inexio
Author URI: https://inexio.jp
Text Domain: inx-update-notification
License: GPL-2.0-or-later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
Copyright 2019 inexio@Takahiro (email : dev@inexio.jp)

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License, version 2, as
published by the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! class_exists( 'InxUpdateNotification' ) ) :

class InxUpdateNotification {
    private $wpdb;

    public $prefix = 'inx_';
    public $tbl_notification;
    public $post_type_split_str = ';';

    public $db_ver_name = 'inx_notification_version';
    public $db_ver = "1.5.4";
    public $sys_ver = "1.5.4";

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;

        $this->tbl_notification  = $this->wpdb->prefix . $this->prefix . 'notification';
        register_activation_hook( __FILE__, array( $this, 'sys_activate') );

        add_action( 'admin_menu', array( $this, 'add_pages' ) );
        add_action( 'plugins_loaded', array( $this, 'check_db_update' ) );
    }

    public function add_pages() {
        add_options_page( 'Update Notification', '更新通知設定',  'manage_options', __FILE__, array( $this, 'page_config' ) );
    }
    
    public function sys_activate() {
        $this->update_db();
    }

    public function check_db_update() {
        $installed_ver = get_option( $this->db_ver_name );
        if ( $installed_ver != $this->db_ver ) {
            $this->update_db();
        }
    }
    
    private function update_db() {
        $charset_collate = $this->wpdb->get_charset_collate();

        $sql = "CREATE TABLE $this->tbl_notification (
                notif_id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                notif_webhook TEXT,
                notif_channel TEXT,
                notif_owner TEXT,
                notif_to TEXT,
                notif_post_type TEXT,
                UNIQUE KEY notif_id (notif_id)
            ) $charset_collate;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql );

        for ( $i = 1; $i <= 7; $i++ ) {
            $results = $this->wpdb->get_results(
                $this->wpdb->prepare(
                    "SELECT * FROM {$this->tbl_notification} WHERE notif_id = %d ORDER BY notif_id ASC", $i
                )
            );

            if ( empty( $results ) ) {
                $this->wpdb->insert(
                    $this->tbl_notification,
                    [
                        'notif_webhook' => '',
                        'notif_channel' => '',
                        'notif_owner' => '',
                        'notif_to' => '',
                        'notif_post_type' => '',
                    ]
                );
            }
        }

        update_option( $this->db_ver_name, $this->db_ver );
    }

    private function send_http_request($url, $args, $request_type = 'POST') {
        $response = wp_remote_request($url, array_merge($args, ['method' => $request_type]));
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->set_error_log(["date" => current_time('mysql'), "line" => __LINE__, "message" => $error_message]);
        }
    }

    public function send_message($chat_type, $webhook, $channel, $owner, $to, $message, $optarr = null) {
        $url = '';
        $args = ['headers' => [], 'body' => ''];
        $request_type = 'POST';

        switch ($chat_type) {
            case "discord":
                $send_message = chr(10);
                if ( ! empty( $owner ) ) {
                    $send_message .= $owner . chr(10);
                }
                $send_message .= $message;

                $url = $webhook;
                $content = [
                    "content" => $send_message
                ];
                $args = [
                    'headers' => ['Content-Type' => 'application/json'],
                    'body' => json_encode($content)
                ];
                break;

            case "line":
                $send_message = chr(10);
                if ( ! empty( $owner ) ) {
                    $send_message .= $owner . chr(10);
                }
                $send_message .= $message;
                $url = "https://notify-api.line.me/api/notify";
                $args['headers'] = ['Authorization' => 'Bearer ' . $webhook];
                $args['body'] = ['message' => $send_message];
                break;

            case "chatwork":
                $send_message = chr(10);
                if ( ! empty( $owner ) ) {
                    $send_message .= $owner . chr(10);
                }
                $send_message .= $message;
                $url = "https://api.chatwork.com/v2/rooms/{$channel}/messages";
                $args['headers'] = ['X-ChatWorkToken' => $webhook];
                $args['body'] = ['body' => $send_message];
                break;

            case "slack":
                $url = $webhook;
                $formatted_message = $message;

                if (!empty($owner)) {
                    $formatted_message = "*{$owner}*: " . $message;
                }

                $args['body'] = json_encode([
                    'channel' => $channel,
                    'text' => $formatted_message,
                ]);
                $args['headers'] = ['Content-Type' => 'application/json'];
                break;

            case "google":
                $url = $webhook;
                $formatted_message = $message;

                if (!empty($owner)) {
                    $formatted_message = "{$owner}: " . $message;
                }

                $args = [
                    'body' => json_encode([
                        'text' => $formatted_message,
                    ]),
                    'headers' => ['Content-Type' => 'application/json']
                ];
                $request_type = 'POST';
                break;

            case "telegram":
                $encoded_message = urlencode($message);
                $url = "https://api.telegram.org/bot{$webhook}/sendMessage?chat_id={$channel}&text={$encoded_message}";
                break;

            case "guilded":
                $url = $webhook;
                $formatted_message = $message;

                if (!empty($owner)) {
                    $formatted_message = "{$owner}: " . $message;
                }

                $args = [
                    'body' => json_encode([
                        "content" => $formatted_message,
                    ]),
                    'headers' => ['Content-Type' => 'application/json']
                ];
                $request_type = 'POST';
                break;
        }

        $this->send_http_request($url, $args, $request_type);
    }

    public function set_error_log($errors) {
        $file = plugin_dir_path( __FILE__ ) . date_i18n("Y") . ".log";
        if ($fp = fopen($file, "a+")) {
            flock($fp, LOCK_EX);
            fwrite($fp, $errors['date'] . "  " . $errors['line']);
            fwrite($fp, chr(10));
            fwrite($fp, print_r($errors['message']));
            fwrite($fp, chr(10));
            fwrite($fp, '----------------------------------');
            fwrite($fp, chr(10));
            fclose($fp);
        }
    }

    public function page_config() {

        $e = new WP_Error();

        $args = [
            '_builtin' => false,
        ];
        $arr = get_post_types( $args );
        $post_type_arr = [
            'post' => 'post',
            'page' => 'page'
        ];
        foreach ( $arr as $key => $val ) {
            $post_type_arr[$key] = $key;
        }

        $updatekey = 'discord';
        $updatekeyStr = 'Discord';
        if ( isset( $_POST['inx-form-' . $updatekey . '-key'] ) && $_POST['inx-form-' . $updatekey . '-key'] ) {
            if ( check_admin_referer( 'inx-form-' . $updatekey . '-key-nonce', 'inx-form-' . $updatekey . '-key' ) ) {
                $notif_id = 7;
                $notif_webhook = isset( $_POST['notif_webhook'] ) ? esc_url_raw( $_POST['notif_webhook'] ) : '';
                $notif_channel = '';
                $notif_owner = isset( $_POST['notif_owner'] ) ? sanitize_text_field( $_POST['notif_owner'] ) : '';
                $notif_to = '';

                $notif_post_type = "";
                if ( isset( $_POST["notif_post_type"] ) ) {
                    foreach ( $_POST["notif_post_type"] as $num => $key ) {
                        $tmp_key = sanitize_key($key);
                        if ( array_key_exists($tmp_key, $post_type_arr) ) {
                            $notif_post_type .= $tmp_key . $this->post_type_split_str;
                        }
                    }
                }

                $col_arr = [];
                $data_arr = [];
                if ( ! empty( $notif_webhook ) ) {
                    $col_arr['notif_webhook'] = $notif_webhook;
                    $col_arr['notif_channel'] = $notif_channel;
                    $col_arr['notif_owner'] = $notif_owner;
                    $col_arr['notif_to'] = $notif_to;
                    $col_arr['notif_post_type'] = $notif_post_type;

                    $data_arr[] = '%s';
                    $data_arr[] = '%s';
                    $data_arr[] = '%s';
                    $data_arr[] = '%s';
                    $data_arr[] = '%s';
                    $data_arr[] = '%d';
                }
                else {
                    $col_arr['notif_webhook'] = "";
                    $col_arr['notif_channel'] = "";
                    $col_arr['notif_owner'] = "";
                    $col_arr['notif_to'] = "";
                    $col_arr['notif_post_type'] = "";

                    $data_arr[] = '%s';
                    $data_arr[] = '%s';
                    $data_arr[] = '%s';
                    $data_arr[] = '%s';
                    $data_arr[] = '%s';
                    $data_arr[] = '%d';
                }

                $this->wpdb->update(
                    $this->tbl_notification,
                    $col_arr,
                    [
                        'notif_id' => $notif_id
                    ],
                    $data_arr
                );
                $e->add(
                    'success',
                    $updatekeyStr . 'の設定を更新しました。'
                );


                set_transient(
                    'inx-notification-updates',
                    $e->get_error_message( 'success' ),
                    2
                );
            }

            wp_safe_redirect( menu_page_url( 'inx-form-' . $updatekey . '-key', false ) );
        }

        $updatekey = 'chatwork';
        $updatekeyStr = 'Chatwork';
        if ( isset( $_POST['inx-form-' . $updatekey . '-key'] ) && $_POST['inx-form-' . $updatekey . '-key'] ) {
            if ( check_admin_referer( 'inx-form-' . $updatekey . '-key-nonce', 'inx-form-' . $updatekey . '-key' ) ) {
                $notif_id = 6;
                $notif_webhook = isset( $_POST['notif_webhook'] ) ? sanitize_text_field( $_POST['notif_webhook'] ) : '';
                $notif_channel = isset( $_POST['notif_channel'] ) ? sanitize_text_field( $_POST['notif_channel'] ) : '';
                $notif_owner = isset( $_POST['notif_owner'] ) ? sanitize_text_field( $_POST['notif_owner'] ) : '';
                $notif_to = '';

                $notif_post_type = "";
                if ( isset( $_POST["notif_post_type"] ) ) {
                    foreach ( $_POST["notif_post_type"] as $num => $key ) {
                        $tmp_key = sanitize_key($key);
                        if ( array_key_exists($tmp_key, $post_type_arr) ) {
                            $notif_post_type .= $tmp_key . $this->post_type_split_str;
                        }
                    }
                }

                $col_arr = [];
                $data_arr = [];
                if ( ! empty( $notif_webhook ) ) {
                    $col_arr['notif_webhook'] = $notif_webhook;
                    $col_arr['notif_channel'] = $notif_channel;
                    $col_arr['notif_owner'] = $notif_owner;
                    $col_arr['notif_to'] = $notif_to;
                    $col_arr['notif_post_type'] = $notif_post_type;

                    $data_arr[] = '%s';
                    $data_arr[] = '%s';
                    $data_arr[] = '%s';
                    $data_arr[] = '%s';
                    $data_arr[] = '%s';
                    $data_arr[] = '%d';
                }
                else {
                    $col_arr['notif_webhook'] = "";
                    $col_arr['notif_channel'] = "";
                    $col_arr['notif_owner'] = "";
                    $col_arr['notif_to'] = "";
                    $col_arr['notif_post_type'] = "";

                    $data_arr[] = '%s';
                    $data_arr[] = '%s';
                    $data_arr[] = '%s';
                    $data_arr[] = '%s';
                    $data_arr[] = '%s';
                    $data_arr[] = '%d';
                }

                $this->wpdb->update(
                    $this->tbl_notification,
                    $col_arr,
                    [
                        'notif_id' => $notif_id
                    ],
                    $data_arr
                );

                $e->add(
                    'success',
                    $updatekeyStr . 'の設定を更新しました。'
                );

                set_transient(
                    'inx-notification-updates',
                    $e->get_error_message( 'success' ),
                    2
                );
            }
            wp_safe_redirect( menu_page_url( 'inx-form-' . $updatekey . '-key', false ) );
        }
        
        $updatekey = 'line';
        $updatekeyStr = 'LINE';
        if ( isset( $_POST['inx-form-' . $updatekey . '-key'] ) && $_POST['inx-form-' . $updatekey . '-key'] ) {
            if ( check_admin_referer( 'inx-form-' . $updatekey . '-key-nonce', 'inx-form-' . $updatekey . '-key' ) ) {
                $notif_id = 5;
                $notif_webhook = isset( $_POST['notif_webhook'] ) ? sanitize_text_field( $_POST['notif_webhook'] ) : '';
                $notif_channel = '';
                $notif_owner = isset( $_POST['notif_owner'] ) ? sanitize_text_field( $_POST['notif_owner'] ) : '';
                $notif_to = '';

                $notif_post_type = "";
                if ( isset( $_POST["notif_post_type"] ) ) {
                    foreach ( $_POST["notif_post_type"] as $num => $key ) {
                        $tmp_key = sanitize_key($key);
                        if ( array_key_exists($tmp_key, $post_type_arr) ) {
                            $notif_post_type .= $tmp_key . $this->post_type_split_str;
                        }
                    }
                }

                $col_arr = [];
                $data_arr = [];
                if ( ! empty( $notif_webhook ) ) {
                    $col_arr['notif_webhook'] = $notif_webhook;
                    $col_arr['notif_channel'] = $notif_channel;
                    $col_arr['notif_owner'] = $notif_owner;
                    $col_arr['notif_to'] = $notif_to;
                    $col_arr['notif_post_type'] = $notif_post_type;

                    $data_arr[] = '%s';
                    $data_arr[] = '%s';
                    $data_arr[] = '%s';
                    $data_arr[] = '%s';
                    $data_arr[] = '%s';
                    $data_arr[] = '%d';
                }
                else {
                    $col_arr['notif_webhook'] = "";
                    $col_arr['notif_channel'] = "";
                    $col_arr['notif_owner'] = "";
                    $col_arr['notif_to'] = "";
                    $col_arr['notif_post_type'] = "";

                    $data_arr[] = '%s';
                    $data_arr[] = '%s';
                    $data_arr[] = '%s';
                    $data_arr[] = '%s';
                    $data_arr[] = '%s';
                    $data_arr[] = '%d';
                }

                $this->wpdb->update(
                    $this->tbl_notification,
                    $col_arr,
                    [
                        'notif_id' => $notif_id
                    ],
                    $data_arr
                );
                $e->add(
                    'success',
                    $updatekeyStr . 'の設定を更新しました。'
                );


                set_transient(
                    'inx-notification-updates',
                    $e->get_error_message( 'success' ),
                    2
                );
            }

            wp_safe_redirect( menu_page_url( 'inx-form-' . $updatekey . '-key', false ) );
        }

        $updatekey = 'slack';
        $updatekeyStr = 'Slack';
        if ( isset( $_POST['inx-form-' . $updatekey . '-key'] ) && $_POST['inx-form-' . $updatekey . '-key'] ) {
            if ( check_admin_referer( 'inx-form-' . $updatekey . '-key-nonce', 'inx-form-' . $updatekey . '-key' ) ) {
                $notif_id = 1;
                $notif_webhook = isset( $_POST['notif_webhook'] ) ? esc_url_raw( $_POST['notif_webhook'] ) : '';
                $notif_channel = isset( $_POST['notif_channel'] ) ? sanitize_text_field( $_POST['notif_channel'] ) : '';
                $notif_owner = isset( $_POST['notif_owner'] ) ? sanitize_text_field( $_POST['notif_owner'] ) : '';
                $notif_to = '';

                $notif_post_type = "";
                if ( isset( $_POST["notif_post_type"] ) ) {
                    foreach ( $_POST["notif_post_type"] as $num => $key ) {
                        $tmp_key = sanitize_key($key);
                        if ( array_key_exists($tmp_key, $post_type_arr) ) {
                            $notif_post_type .= $tmp_key . $this->post_type_split_str;
                        }
                    }
                }

                $col_arr = [];
                $data_arr = [];
                if ( ! empty( $notif_webhook ) ) {
                    $col_arr['notif_webhook'] = $notif_webhook;
                    $col_arr['notif_channel'] = $notif_channel;
                    $col_arr['notif_owner'] = $notif_owner;
                    $col_arr['notif_to'] = $notif_to;
                    $col_arr['notif_post_type'] = $notif_post_type;

                    $data_arr[] = '%s';
                    $data_arr[] = '%s';
                    $data_arr[] = '%s';
                    $data_arr[] = '%s';
                    $data_arr[] = '%s';
                    $data_arr[] = '%d';
                }
                else {
                    $col_arr['notif_webhook'] = "";
                    $col_arr['notif_channel'] = "";
                    $col_arr['notif_owner'] = "";
                    $col_arr['notif_to'] = "";
                    $col_arr['notif_post_type'] = "";

                    $data_arr[] = '%s';
                    $data_arr[] = '%s';
                    $data_arr[] = '%s';
                    $data_arr[] = '%s';
                    $data_arr[] = '%s';
                    $data_arr[] = '%d';
                }

                $this->wpdb->update(
                    $this->tbl_notification,
                    $col_arr,
                    [
                        'notif_id' => $notif_id
                    ],
                    $data_arr
                );
                $e->add(
                    'success',
                    $updatekeyStr . 'の設定を更新しました。'
                );


                set_transient(
                    'inx-notification-updates',
                    $e->get_error_message( 'success' ),
                    2
                );
            }

            wp_safe_redirect( menu_page_url( 'inx-form-' . $updatekey . '-key', false ) );
        }

        $updatekey = 'telegram';
        $updatekeyStr = 'Telegram';
        if ( isset( $_POST['inx-form-' . $updatekey . '-key'] ) && $_POST['inx-form-' . $updatekey . '-key'] ) {
            if ( check_admin_referer( 'inx-form-' . $updatekey . '-key-nonce', 'inx-form-' . $updatekey . '-key' ) ) {
                $notif_id = 4;
                $notif_webhook = isset( $_POST['notif_webhook'] ) ? sanitize_text_field( $_POST['notif_webhook'] ) : '';
                $notif_channel = isset( $_POST['notif_channel'] ) ? sanitize_text_field( $_POST['notif_channel'] ) : '';

                $notif_post_type = "";
                if ( isset( $_POST["notif_post_type"] ) ) {
                    foreach ( $_POST["notif_post_type"] as $num => $key ) {
                        $tmp_key = sanitize_key($key);
                        if ( array_key_exists($tmp_key, $post_type_arr) ) {
                            $notif_post_type .= $tmp_key . $this->post_type_split_str;
                        }
                    }
                }

                $col_arr = [];
                $data_arr = [];
                if ( ! empty( $notif_webhook ) ) {
                    $col_arr['notif_webhook'] = $notif_webhook;
                    $col_arr['notif_channel'] = $notif_channel;
                    $col_arr['notif_post_type'] = $notif_post_type;

                    $data_arr[] = '%s';
                    $data_arr[] = '%s';
                    $data_arr[] = '%s';
                    $data_arr[] = '%d';
                }
                else {
                    $col_arr['notif_webhook'] = "";
                    $col_arr['notif_channel'] = "";
                    $col_arr['notif_post_type'] = "";

                    $data_arr[] = '%s';
                    $data_arr[] = '%s';
                    $data_arr[] = '%s';
                    $data_arr[] = '%d';
                }

                $this->wpdb->update(
                    $this->tbl_notification,
                    $col_arr,
                    [
                        'notif_id' => $notif_id
                    ],
                    $data_arr
                );
                $e->add(
                    'success',
                    $updatekeyStr . 'の設定を更新しました。'
                );


                set_transient(
                    'inx-notification-updates',
                    $e->get_error_message( 'success' ),
                    2
                );
            }

            wp_safe_redirect( menu_page_url( 'inx-form-' . $updatekey . '-key', false ) );
        }

        $updatekey = 'guilded';
        $updatekeyStr = 'Guilded';
        if ( isset( $_POST['inx-form-' . $updatekey . '-key'] ) && $_POST['inx-form-' . $updatekey . '-key'] ) {
            if ( check_admin_referer( 'inx-form-' . $updatekey . '-key-nonce', 'inx-form-' . $updatekey . '-key' ) ) {
                $notif_id = 3;
                $notif_webhook = isset( $_POST['notif_webhook'] ) ? esc_url_raw( $_POST['notif_webhook'] ) : '';
                $notif_channel = '';
                $notif_owner = '';
                $notif_to = '';

                $notif_post_type = "";
                if ( isset( $_POST["notif_post_type"] ) ) {
                    foreach ( $_POST["notif_post_type"] as $num => $key ) {
                        $tmp_key = sanitize_key($key);
                        if ( array_key_exists($tmp_key, $post_type_arr) ) {
                            $notif_post_type .= $tmp_key . $this->post_type_split_str;
                        }
                    }
                }

                $col_arr = [];
                $data_arr = [];
                if ( ! empty( $notif_webhook ) ) {
                    $col_arr['notif_webhook'] = $notif_webhook;
                    $col_arr['notif_channel'] = $notif_channel;
                    $col_arr['notif_owner'] = $notif_owner;
                    $col_arr['notif_to'] = $notif_to;
                    $col_arr['notif_post_type'] = $notif_post_type;

                    $data_arr[] = '%s';
                    $data_arr[] = '%s';
                    $data_arr[] = '%s';
                    $data_arr[] = '%s';
                    $data_arr[] = '%s';
                    $data_arr[] = '%d';
                }
                else {
                    $col_arr['notif_webhook'] = "";
                    $col_arr['notif_channel'] = "";
                    $col_arr['notif_owner'] = "";
                    $col_arr['notif_to'] = "";
                    $col_arr['notif_post_type'] = "";

                    $data_arr[] = '%s';
                    $data_arr[] = '%s';
                    $data_arr[] = '%s';
                    $data_arr[] = '%s';
                    $data_arr[] = '%s';
                    $data_arr[] = '%d';
                }

                $this->wpdb->update(
                    $this->tbl_notification,
                    $col_arr,
                    [
                        'notif_id' => $notif_id
                    ],
                    $data_arr
                );

                $e->add(
                    'success',
                    $updatekeyStr . 'の設定を更新しました。'
                );


                set_transient(
                    'inx-notification-updates',
                    $e->get_error_message( 'success' ),
                    2
                );
            }

            wp_safe_redirect( menu_page_url( 'inx-form-' . $updatekey . '-key', false ) );
        }

        $updatekey = 'google';
        $updatekeyStr = 'Google Chat';
        if ( isset( $_POST['inx-form-' . $updatekey . '-key'] ) && $_POST['inx-form-' . $updatekey . '-key'] ) {
            if ( check_admin_referer( 'inx-form-' . $updatekey . '-key-nonce', 'inx-form-' . $updatekey . '-key' ) ) {
                $notif_id = 2;
                $notif_webhook = isset( $_POST['notif_webhook'] ) ? esc_url_raw( $_POST['notif_webhook'] ) : '';
                $notif_channel = isset( $_POST['notif_channel'] ) ? sanitize_text_field( $_POST['notif_channel'] ) : '';
                $notif_owner = '';
                $notif_to = isset( $_POST['notif_to'] ) ? sanitize_text_field( $_POST['notif_to'] ) : '';

                $notif_post_type = "";
                if ( isset( $_POST["notif_post_type"] ) ) {
                    foreach ( $_POST["notif_post_type"] as $num => $key ) {
                        $tmp_key = sanitize_key($key);
                        if ( array_key_exists($tmp_key, $post_type_arr) ) {
                            $notif_post_type .= $tmp_key . $this->post_type_split_str;
                        }
                    }
                }

                $col_arr = [];
                $data_arr = [];
                if ( ! empty( $notif_webhook ) ) {
                    $col_arr['notif_webhook'] = $notif_webhook;
                    $col_arr['notif_channel'] = $notif_channel;
                    $col_arr['notif_owner'] = $notif_owner;
                    $col_arr['notif_to'] = $notif_to;
                    $col_arr['notif_post_type'] = $notif_post_type;

                    $data_arr[] = '%s';
                    $data_arr[] = '%s';
                    $data_arr[] = '%s';
                    $data_arr[] = '%s';
                    $data_arr[] = '%s';
                    $data_arr[] = '%d';
                }
                else {
                    $col_arr['notif_webhook'] = "";
                    $col_arr['notif_channel'] = "";
                    $col_arr['notif_owner'] = "";
                    $col_arr['notif_to'] = "";
                    $col_arr['notif_post_type'] = "";

                    $data_arr[] = '%s';
                    $data_arr[] = '%s';
                    $data_arr[] = '%s';
                    $data_arr[] = '%s';
                    $data_arr[] = '%s';
                    $data_arr[] = '%d';
                }

                $this->wpdb->update(
                    $this->tbl_notification,
                    $col_arr,
                    [
                        'notif_id' => $notif_id
                    ],
                    $data_arr
                );
                $e->add(
                    'success',
                    $updatekeyStr . 'の設定を更新しました。'
                );

                set_transient(
                    'inx-notification-updates',
                    $e->get_error_message( 'success' ),
                    2
                );
            }

            wp_safe_redirect( menu_page_url( 'inx-form-' . $updatekey . '-key', false ) );
        }

        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM $this->tbl_notification WHERE 1=%d ORDER BY notif_id ASC", 1 )
        );
?>
<div class="wrap">
    <h2>Update Notification（更新通知設定） v<?php echo esc_html($this->sys_ver); ?></h2>

        <?php if ( $messages = get_transient( "inx-notification-updates" ) ) : ?>
    <div class="updated">
        <ul>
        <li><?php echo esc_html( $messages ); ?></li>
        </ul>
    </div>
        <?php endif; ?>

    <style type="text/css">
    .input-notif_webhook {
    width: 100%;
    }
    .input-notif_channel {
    width: 35%;
    min-width: 300px;
    }
    .wp-editor-area {
    height: 48px;
    width: 360px;
    }
    .inx-form--section form:nth-child(odd) {
    clear: both;
    }
    .inx-form--section__form {
    margin-bottom:3.0rem;float:left;width:50%;padding-left:10px;padding-right:10px;box-sizing: border-box;
    }
    </style>
    <script>
    document.addEventListener('DOMContentLoaded', function(){
    let btn_discord = document.getElementById('btncnf_discord');
    btn_discord.addEventListener('click', function(){
        document.getElementById('inx-form-discord').submit();
    });
    let btn_line = document.getElementById('btncnf_line');
    btn_line.addEventListener('click', function(){
        document.getElementById('inx-form-line').submit();
    });
    let btn_chatwork = document.getElementById('btncnf_chatwork');
    btn_chatwork.addEventListener('click', function(){
        document.getElementById('inx-form-chatwork').submit();
    });
    let btn_slack = document.getElementById('btncnf_slack');
    btn_slack.addEventListener('click', function(){
        document.getElementById('inx-form-slack').submit();
    });
    let btn_telegram = document.getElementById('btncnf_telegram');
    btn_telegram.addEventListener('click', function(){
        document.getElementById('inx-form-telegram').submit();
    });
    let btn_guilded = document.getElementById('btncnf_guilded');
    btn_guilded.addEventListener('click', function(){
        document.getElementById('inx-form-guilded').submit();
    });
    let btn_google = document.getElementById('btncnf_google');
    btn_google.addEventListener('click', function(){
        document.getElementById('inx-form-google').submit();
    });
    });
    </script>

    <div class="inx-form--section">
    <?php $result_num = 4; ?>
    <?php $target_system = "line"; ?>
    <?php if (isset($results[$result_num])) : ?>
    <form id="inx-form-<?php echo esc_html($target_system); ?>" action="" method="post" enctype="multipart/form-data" class="inx-form--section__form">
        <?php wp_nonce_field( 'inx-form-'.$target_system.'-key-nonce', 'inx-form-'.$target_system.'-key' ); ?>
        <input type="hidden" id="<?php echo esc_html($target_system); ?>_notif_id" name="notif_id" value="<?php echo esc_html($results[$result_num]->notif_id); ?>" />
        <input type="hidden" id="<?php echo esc_html($target_system); ?>_notif_to" name="notif_to" value="<?php echo esc_html($results[$result_num]->notif_to); ?>" />
        <h3>LINE設定</h3>
        <p>設定を解除する場合は、トークン を空白にして「更新」をクリックしてください。</p>
        <table class="wp-list-table widefat plugins">
        <tbody id="the-list">
            <tr class="inactive">
            <th style="width:150px;">トークン</th>
            <td>
                <input type="text"
                    id="<?php echo esc_html($target_system); ?>_notif_webhook"
                    name="notif_webhook"
                    class="input-notif_webhook"
                    value="<?php echo esc_html($results[$result_num]->notif_webhook); ?>" />
            </td>
            </tr>
            <tr class="inactive">
            <th>送信者名</th>
            <td style="vertical-align:middle;">
                <input type="text"
                    id="<?php echo esc_html($target_system); ?>_notif_owner"
                    name="notif_owner"
                    class="input-notif_owner"
                    value="<?php echo esc_html($results[$result_num]->notif_owner); ?>" />
            </td>
            </tr>
            <tr class="inactive">
            <th>通知種類</th>
            <td style="vertical-align:middle;">
<?php
        $tmp = esc_html($results[$result_num]->notif_post_type);
        $notif_post_types = [];
        if ( ! empty($tmp) ) {
            $tmp_arr = explode($this->post_type_split_str, $tmp);
            foreach ( $tmp_arr as $num => $val ) {
                if ( ! empty( $val ) ) {
                    $notif_post_types[$val] = $val;
                }
            }
        }

        foreach ( $post_type_arr as $num => $val ) :
            $check = '';
            if ( array_key_exists($val, $notif_post_types) ) {
                $check = 'checked="checked" ';
            }
?>
                <div style="display:inline-block;height:1%;line-height:1.0rem; margin-right:2.0rem;">
                <input id="notif_post_type_<?php echo esc_html($target_system); ?>_<?php echo esc_html($val); ?>" name="notif_post_type[]" style="margin:0;" type="checkbox" <?php echo esc_html($check); ?>value="<?php echo esc_html($val); ?>">
                <label for="notif_post_type_<?php echo esc_html($target_system); ?>_<?php echo esc_html($val); ?>" class="" style="vertical-align: text-top;"><?php echo esc_html($val); ?></label>
                </div>
        <?php endforeach; ?>
            </td>
            </tr>
        </tbody>
        </table>
        <p class="submit">
        <input type="button" id="btncnf_<?php echo esc_html($target_system); ?>" value="更新" class="inx-btn inx-btn-cnf button button-primary button-small">
        </p>
    </form>
    <?php endif; ?>

    <?php $result_num = 0; ?>
    <?php $target_system = "slack"; ?>
    <?php if (isset($results[$result_num])) : ?>
    <form id="inx-form-<?php echo esc_html($target_system); ?>" action="" method="post" enctype="multipart/form-data" class="inx-form--section__form">
        <?php wp_nonce_field( 'inx-form-'.$target_system.'-key-nonce', 'inx-form-'.$target_system.'-key' ); ?>
        <input type="hidden" id="<?php echo esc_html($target_system); ?>_notif_id" name="notif_id" value="<?php echo esc_html($results[$result_num]->notif_id); ?>" />
        <input type="hidden" id="<?php echo esc_html($target_system); ?>_notif_to" name="notif_to" value="<?php echo esc_html($results[$result_num]->notif_to); ?>" />
        <h3>Slack設定</h3>
        <p>設定を解除する場合は、Webhook URL を空白にして「更新」をクリックしてください。</p>
        <table class="wp-list-table widefat plugins">
        <tbody id="the-list">
            <tr class="inactive">
            <th style="width:150px;">Webhook URL</th>
            <td>
                <input type="text"
                    id="<?php echo esc_html($target_system); ?>_notif_webhook"
                    name="notif_webhook"
                    class="input-notif_webhook"
                    value="<?php echo esc_html($results[$result_num]->notif_webhook); ?>" />
            </td>
            </tr>
            <tr class="inactive">
            <th>チャンネル</th>
            <td style="vertical-align:middle;">
                <input type="text"
                    id="<?php echo esc_html($target_system); ?>_notif_channel"
                    name="notif_channel"
                    class="input-notif_channel"
                    value="<?php echo esc_html($results[$result_num]->notif_channel); ?>" />
            </td>
            </tr>
            <tr class="inactive">
            <th>送信者名</th>
            <td style="vertical-align:middle;">
                <input type="text"
                    id="<?php echo esc_html($target_system); ?>_notif_owner"
                    name="notif_owner"
                    class="input-notif_owner"
                    value="<?php echo esc_html($results[$result_num]->notif_owner); ?>" />
            </td>
            </tr>
            <tr class="inactive">
            <th>通知種類</th>
            <td style="vertical-align:middle;">
<?php
        $tmp = esc_html($results[$result_num]->notif_post_type);
        $notif_post_types = [];
        if ( ! empty($tmp) ) {
            $tmp_arr = explode($this->post_type_split_str, $tmp);
            foreach ( $tmp_arr as $num => $val ) {
                if ( ! empty( $val ) ) {
                    $notif_post_types[$val] = $val;
                }
            }
        }
        foreach ( $post_type_arr as $num => $val ) :
            $check = '';
            if ( array_key_exists($val, $notif_post_types) ) {
                $check = 'checked="checked" ';
            }
?>
                <div style="display:inline-block;height:1%;line-height:1.0rem; margin-right:2.0rem;">
                <input id="notif_post_type_<?php echo esc_html($target_system); ?>_<?php echo esc_html($val); ?>" name="notif_post_type[]" style="margin:0;" type="checkbox" <?php echo esc_html($check); ?>value="<?php echo esc_html($val); ?>">
                <label for="notif_post_type_<?php echo esc_html($target_system); ?>_<?php echo esc_html($val); ?>" class="" style="vertical-align: text-top;"><?php echo esc_html($val); ?></label>
                </div>
        <?php endforeach; ?>
            </td>
            </tr>
        </tbody>
        </table>
        <p class="submit">
        <input type="button" id="btncnf_<?php echo esc_html($target_system); ?>" value="更新" class="inx-btn inx-btn-cnf button button-primary button-small">
        </p>
    </form>
    <?php endif; ?>

    <?php $result_num = 5; ?>
    <?php $target_system = "chatwork"; ?>
    <?php if (isset($results[$result_num])) : ?>
    <form id="inx-form-<?php echo esc_html($target_system); ?>" action="" method="post" enctype="multipart/form-data" class="inx-form--section__form">
        <?php wp_nonce_field( 'inx-form-'.$target_system.'-key-nonce', 'inx-form-'.$target_system.'-key' ); ?>
        <input type="hidden" id="<?php echo esc_html($target_system); ?>_notif_id" name="notif_id" value="<?php echo esc_html($results[$result_num]->notif_id); ?>" />
        <input type="hidden" id="<?php echo esc_html($target_system); ?>_notif_to" name="notif_to" value="<?php echo esc_html($results[$result_num]->notif_to); ?>" />
        <h3>Chatwork設定</h3>
        <p>設定を解除する場合は、APIトークン を空白にして「更新」をクリックしてください。</p>
        <table class="wp-list-table widefat plugins">
        <tbody id="the-list">
            <tr class="inactive">
            <th style="width:150px;">APIトークン</th>
            <td>
                <input type="text"
                    id="<?php echo esc_html($target_system); ?>_notif_webhook"
                    name="notif_webhook"
                    class="input-notif_webhook"
                    value="<?php echo esc_html($results[$result_num]->notif_webhook); ?>" />
            </td>
            </tr>
            <tr class="inactive">
            <th>ルームID</th>
            <td style="vertical-align:middle;">
                <input type="text"
                    id="<?php echo esc_html($target_system); ?>_notif_channel"
                    name="notif_channel"
                    class="input-notif_channel"
                    value="<?php echo esc_html($results[$result_num]->notif_channel); ?>" />
            </td>
            </tr>
            <tr class="inactive">
            <th>送信者名</th>
            <td style="vertical-align:middle;">
                <input type="text"
                    id="<?php echo esc_html($target_system); ?>_notif_owner"
                    name="notif_owner"
                    class="input-notif_owner"
                    value="<?php echo esc_html($results[$result_num]->notif_owner); ?>" />
            </td>
            </tr>
            <tr class="inactive">
            <th>通知種類</th>
            <td style="vertical-align:middle;">
<?php
        $tmp = esc_html($results[$result_num]->notif_post_type);
        $notif_post_types = [];
        if ( ! empty($tmp) ) {
            $tmp_arr = explode($this->post_type_split_str, $tmp);
            foreach ( $tmp_arr as $num => $val ) {
                if ( ! empty( $val ) ) {
                    $notif_post_types[$val] = $val;
                }
            }
        }
        foreach ( $post_type_arr as $num => $val ) :
            $check = '';
            if ( array_key_exists($val, $notif_post_types) ) {
                $check = 'checked="checked" ';
            }
?>
                <div style="display:inline-block;height:1%;line-height:1.0rem; margin-right:2.0rem;">
                <input id="notif_post_type_<?php echo esc_html($target_system); ?>_<?php echo esc_html($val); ?>" name="notif_post_type[]" style="margin:0;" type="checkbox" <?php echo esc_html($check); ?>value="<?php echo esc_html($val); ?>">
                <label for="notif_post_type_<?php echo esc_html($target_system); ?>_<?php echo esc_html($val); ?>" class="" style="vertical-align: text-top;"><?php echo esc_html($val); ?></label>
                </div>
        <?php endforeach; ?>
            </td>
            </tr>
        </tbody>
        </table>
        <p class="submit">
        <input type="button" id="btncnf_<?php echo esc_html($target_system); ?>" value="更新" class="inx-btn inx-btn-cnf button button-primary button-small">
        </p>
    </form>
    <?php endif; ?>

    <?php $result_num = 6; ?>
    <?php $target_system = "discord"; ?>
    <?php if (isset($results[$result_num])) : ?>
    <form id="inx-form-<?php echo esc_html($target_system); ?>" action="" method="post" enctype="multipart/form-data" class="inx-form--section__form">
        <?php wp_nonce_field( 'inx-form-'.$target_system.'-key-nonce', 'inx-form-'.$target_system.'-key' ); ?>
        <input type="hidden" id="<?php echo esc_html($target_system); ?>_notif_id" name="notif_id" value="<?php echo esc_html($results[$result_num]->notif_id); ?>" />
        <input type="hidden" id="<?php echo esc_html($target_system); ?>_notif_to" name="notif_to" value="<?php echo esc_html($results[$result_num]->notif_to); ?>" />
        <h3>Discord設定</h3>
        <p>設定を解除する場合は、Webhook URL を空白にして「更新」をクリックしてください。</p>
        <table class="wp-list-table widefat plugins">
        <tbody id="the-list">
            <tr class="inactive">
            <th style="width:150px;">Webhook URL</th>
            <td>
                <input type="text"
                    id="<?php echo esc_html($target_system); ?>_notif_webhook"
                    name="notif_webhook"
                    class="input-notif_webhook"
                    value="<?php echo esc_html($results[$result_num]->notif_webhook); ?>" />
            </td>
            </tr>
            <tr class="inactive">
            <th>送信者名</th>
            <td style="vertical-align:middle;">
                <input type="text"
                    id="<?php echo esc_html($target_system); ?>_notif_owner"
                    name="notif_owner"
                    class="input-notif_owner"
                    value="<?php echo esc_html($results[$result_num]->notif_owner); ?>" />
            </td>
            </tr>
            <tr class="inactive">
            <th>通知種類</th>
            <td style="vertical-align:middle;">
<?php
        $tmp = esc_html($results[$result_num]->notif_post_type);
        $notif_post_types = [];
        if ( ! empty($tmp) ) {
            $tmp_arr = explode($this->post_type_split_str, $tmp);
            foreach ( $tmp_arr as $num => $val ) {
                if ( ! empty( $val ) ) {
                    $notif_post_types[$val] = $val;
                }
            }
        }
        foreach ( $post_type_arr as $num => $val ) :
            $check = '';
            if ( array_key_exists($val, $notif_post_types) ) {
                $check = 'checked="checked" ';
            }
?>
                <div style="display:inline-block;height:1%;line-height:1.0rem; margin-right:2.0rem;">
                <input id="notif_post_type_<?php echo esc_html($target_system); ?>_<?php echo esc_html($val); ?>" name="notif_post_type[]" style="margin:0;" type="checkbox" <?php echo esc_html($check); ?>value="<?php echo esc_html($val); ?>">
                <label for="notif_post_type_<?php echo esc_html($target_system); ?>_<?php echo esc_html($val); ?>" class="" style="vertical-align: text-top;"><?php echo esc_html($val); ?></label>
                </div>
        <?php endforeach; ?>
            </td>
            </tr>
        </tbody>
        </table>
        <p class="submit">
        <input type="button" id="btncnf_<?php echo esc_html($target_system); ?>" value="更新" class="inx-btn inx-btn-cnf button button-primary button-small">
        </p>
    </form>
    <?php endif; ?>

    <?php $result_num = 3; ?>
    <?php $target_system = "telegram"; ?>
    <?php if (isset($results[$result_num])) : ?>
    <form id="inx-form-<?php echo esc_html($target_system); ?>" action="" method="post" enctype="multipart/form-data" class="inx-form--section__form">
        <?php wp_nonce_field( 'inx-form-'.$target_system.'-key-nonce', 'inx-form-'.$target_system.'-key' ); ?>
        <input type="hidden" id="<?php echo esc_html($target_system); ?>_notif_id" name="notif_id" value="<?php echo esc_html($results[$result_num]->notif_id); ?>" />
        <input type="hidden" id="<?php echo esc_html($target_system); ?>_notif_to" name="notif_to" value="<?php echo esc_html($results[$result_num]->notif_to); ?>" />
        <h3>Telegram設定</h3>
        <p>設定を解除する場合は、Bot API Token を空白にして「更新」をクリックしてください。</p>
        <table class="wp-list-table widefat plugins">
        <tbody id="the-list">
            <tr class="inactive">
            <th style="width:150px;">Bot API Token</th>
            <td>
                <input type="text"
                    id="<?php echo esc_html($target_system); ?>_notif_webhook"
                    name="notif_webhook"
                    class="input-notif_webhook"
                    value="<?php echo esc_html($results[$result_num]->notif_webhook); ?>" />
            </td>
            </tr>
            <tr class="inactive">
            <th>Chat ID</th>
            <td style="vertical-align:middle;">
                <input type="text"
                    id="<?php echo esc_html($target_system); ?>_notif_channel"
                    name="notif_channel"
                    class="input-notif_channel"
                    value="<?php echo esc_html($results[$result_num]->notif_channel); ?>" />
            </td>
            </tr>
            <tr class="inactive">
            <th>通知種類</th>
            <td style="vertical-align:middle;">
<?php
        $tmp = esc_html($results[$result_num]->notif_post_type);
        $notif_post_types = [];
        if ( ! empty($tmp) ) {
            $tmp_arr = explode($this->post_type_split_str, $tmp);
            foreach ( $tmp_arr as $num => $val ) {
                if ( ! empty( $val ) ) {
                    $notif_post_types[$val] = $val;
                }
            }
        }
        foreach ( $post_type_arr as $num => $val ) :
            $check = '';
            if ( array_key_exists($val, $notif_post_types) ) {
                $check = 'checked="checked" ';
            }
?>
                <div style="display:inline-block;height:1%;line-height:1.0rem; margin-right:2.0rem;">
                <input id="notif_post_type_<?php echo esc_html($target_system); ?>_<?php echo esc_html($val); ?>" name="notif_post_type[]" style="margin:0;" type="checkbox" <?php echo esc_html($check); ?>value="<?php echo esc_html($val); ?>">
                <label for="notif_post_type_<?php echo esc_html($target_system); ?>_<?php echo esc_html($val); ?>" class="" style="vertical-align: text-top;"><?php echo esc_html($val); ?></label>
                </div>
        <?php endforeach; ?>
            </td>
            </tr>
        </tbody>
        </table>
        <p class="submit">
        <input type="button" id="btncnf_<?php echo esc_html($target_system); ?>" value="更新" class="inx-btn inx-btn-cnf button button-primary button-small">
        </p>
    </form>
    <?php endif; ?>

    <?php $result_num = 2; ?>
    <?php $target_system = "guilded"; ?>
    <?php if (isset($results[$result_num])) : ?>
    <form id="inx-form-<?php echo esc_html($target_system); ?>" action="" method="post" enctype="multipart/form-data" class="inx-form--section__form">
        <?php wp_nonce_field( 'inx-form-'.$target_system.'-key-nonce', 'inx-form-'.$target_system.'-key' ); ?>
        <input type="hidden" id="<?php echo esc_html($target_system); ?>_notif_id" name="notif_id" value="<?php echo esc_html($results[$result_num]->notif_id); ?>" />
        <input type="hidden" id="<?php echo esc_html($target_system); ?>_notif_to" name="notif_to" value="<?php echo esc_html($results[$result_num]->notif_to); ?>" />
        <h3>Guilded設定</h3>
        <p>設定を解除する場合は、Webhook URL を空白にして「更新」をクリックしてください。</p>
        <table class="wp-list-table widefat plugins">
        <tbody id="the-list">
            <tr class="inactive">
            <th style="width:150px;">Webhook URL</th>
            <td>
                <input type="text"
                    id="<?php echo esc_html($target_system); ?>_notif_webhook"
                    name="notif_webhook"
                    class="input-notif_webhook"
                    value="<?php echo esc_html($results[$result_num]->notif_webhook); ?>" />
            </td>
            </tr>
            <tr class="inactive">
            <th>通知種類</th>
            <td style="vertical-align:middle;">
<?php
        $tmp = esc_html($results[$result_num]->notif_post_type);
        $notif_post_types = [];
        if ( ! empty($tmp) ) {
            $tmp_arr = explode($this->post_type_split_str, $tmp);
            foreach ( $tmp_arr as $num => $val ) {
                if ( ! empty( $val ) ) {
                    $notif_post_types[$val] = $val;
                }
            }
        }
        foreach ( $post_type_arr as $num => $val ) :
            $check = '';
            if ( array_key_exists($val, $notif_post_types) ) {
                $check = 'checked="checked" ';
            }
?>
                <div style="display:inline-block;height:1%;line-height:1.0rem; margin-right:2.0rem;">
                <input id="notif_post_type_<?php echo esc_html($target_system); ?>_<?php echo esc_html($val); ?>" name="notif_post_type[]" style="margin:0;" type="checkbox" <?php echo esc_html($check); ?>value="<?php echo esc_html($val); ?>">
                <label for="notif_post_type_<?php echo esc_html($target_system); ?>_<?php echo esc_html($val); ?>" class="" style="vertical-align: text-top;"><?php echo esc_html($val); ?></label>
                </div>
        <?php endforeach; ?>
            </td>
            </tr>
        </tbody>
        </table>
        <p class="submit">
        <input type="button" id="btncnf_<?php echo esc_html($target_system); ?>" value="更新" class="inx-btn inx-btn-cnf button button-primary button-small">
        </p>
    </form>
    <?php endif; ?>

    <?php $result_num = 1; ?>
    <?php $target_system = "google"; ?>
    <?php if (isset($results[$result_num])) : ?>
    <form id="inx-form-<?php echo esc_html($target_system); ?>" action="" method="post" enctype="multipart/form-data" class="inx-form--section__form">
        <?php wp_nonce_field( 'inx-form-'.$target_system.'-key-nonce', 'inx-form-'.$target_system.'-key' ); ?>
        <input type="hidden" id="<?php echo esc_html($target_system); ?>_notif_id" name="notif_id" value="<?php echo esc_html($results[$result_num]->notif_id); ?>" />
        <input type="hidden" id="<?php echo esc_html($target_system); ?>_notif_owner" name="notif_owner" value="<?php echo esc_html($results[$result_num]->notif_owner); ?>" />
        <h3>Google Chat設定</h3>
        <p>スレッドが未入力の場合は、新しいメッセージとして通知します。</p>
        <p>設定を解除する場合は、Webhook URL を空白にして「更新」をクリックしてください。</p>
        <table class="wp-list-table widefat plugins">
        <tbody id="the-list">
            <tr class="inactive">
            <th style="width:150px;">Webhook URL</th>
            <td>
                <input type="text"
                    id="<?php echo esc_html($target_system); ?>_notif_webhook"
                    name="notif_webhook"
                    class="input-notif_webhook"
                    value="<?php echo esc_html($results[$result_num]->notif_webhook); ?>" />
            </td>
            </tr>
            <tr class="inactive">
            <th>スレッド</th>
            <td style="vertical-align:middle;">
                <input type="text"
                    id="<?php echo esc_html($target_system); ?>_notif_channel"
                    name="notif_channel"
                    class="input-notif_channel"
                    value="<?php echo esc_html($results[$result_num]->notif_channel); ?>" />
            </td>
            </tr>
            <tr class="inactive">
            <th>宛先</th>
            <td style="vertical-align:middle;">
                <input type="text"
                    id="<?php echo esc_html($target_system); ?>_notif_to"
                    name="notif_to"
                    class="input-notif_to"
                    value="<?php echo esc_html($results[$result_num]->notif_to); ?>" />
            </td>
            </tr>
            <tr class="inactive">
            <th>通知種類</th>
            <td style="vertical-align:middle;">
<?php
        $tmp = esc_html($results[$result_num]->notif_post_type);
        $notif_post_types = [];
        if ( ! empty($tmp) ) {
            $tmp_arr = explode($this->post_type_split_str, $tmp);
            foreach ( $tmp_arr as $num => $val ) {
                if ( ! empty( $val ) ) {
                    $notif_post_types[$val] = $val;
                }
            }
        }
        foreach ( $post_type_arr as $num => $val ) :
            $check = '';
            if ( array_key_exists($val, $notif_post_types) ) {
                $check = 'checked="checked" ';
            }
?>
                <div style="display:inline-block;height:1%;line-height:1.0rem; margin-right:2.0rem;">
                <input id="notif_post_type_<?php echo esc_html($target_system); ?>_<?php echo esc_html($val); ?>" name="notif_post_type[]" style="margin:0;" type="checkbox" <?php echo esc_html($check); ?>value="<?php echo esc_html($val); ?>">
                <label for="notif_post_type_<?php echo esc_html($target_system); ?>_<?php echo esc_html($val); ?>" class="" style="vertical-align: text-top;"><?php echo esc_html($val); ?></label>
                </div>
        <?php endforeach; ?>
            </td>
            </tr>
        </tbody>
        </table>
        <p class="submit">
        <input type="button" id="btncnf_<?php echo esc_html($target_system); ?>" value="更新" class="inx-btn inx-btn-cnf button button-primary button-small">
        </p>
    </form>
    <?php endif; ?>

    </div><!-- /.inx-form--section -->
    </div><!-- /.wrap -->
<?php
    }
}

$inxUpdateNotification = new InxUpdateNotification;

function inx_catch_saving( $post_ID, $post, $update ) {
    global $wpdb;
    global $inxUpdateNotification;

    if ( wp_is_post_revision($post_ID) || preg_match("/\A(auto\-draft|draft)\z/u", $post->post_status) ) {
        return;
    }

    $update_message = '更新';
    if ( $post->post_status == "trash" ) {
        $update_message = "ゴミ箱に移動";
    }

    $results = $wpdb->get_results(
        $wpdb->prepare(
        "SELECT * FROM {$inxUpdateNotification->tbl_notification} WHERE 1=%d ORDER BY notif_id ASC", 1 ) );

    $target_soeji = 0;
    if ( ! empty( $results[$target_soeji]->notif_webhook ) && ! empty( $results[$target_soeji]->notif_channel ) ) {
        $tmp = esc_html($results[$target_soeji]->notif_post_type);
        $notif_post_types = [];
        if ( ! empty($tmp) ) {
            $tmp_arr = explode($inxUpdateNotification->post_type_split_str, $tmp);
            foreach ( $tmp_arr as $num => $val ) {
                if ( ! empty( $val ) ) {
                    $notif_post_types[$val] = $val;
                }
            }

            $post_type = get_post_type( $post_ID );

            if ( array_key_exists($post_type, $notif_post_types) ) {
                $message = date_i18n("Y/m/d H:i:s") . " WordPressが" . $update_message . "されました。" . chr(10) . get_permalink($post_ID);
                $inxUpdateNotification->send_message(
                    'slack',
                    $results[$target_soeji]->notif_webhook,
                    $results[$target_soeji]->notif_channel,
                    $results[$target_soeji]->notif_owner,
                    '',
                    $message
                );
            }
        }
    }

    $target_soeji = 1;
    if ( ! empty( $results[$target_soeji]->notif_webhook ) ) {
        $tmp = esc_html($results[$target_soeji]->notif_post_type);
        $notif_post_types = [];
        if ( ! empty($tmp) ) {
            $tmp_arr = explode($inxUpdateNotification->post_type_split_str, $tmp);
            foreach ( $tmp_arr as $num => $val ) {
                if ( ! empty( $val ) ) {
                    $notif_post_types[$val] = $val;
                }
            }

            $post_type = get_post_type( $post_ID );

            if ( array_key_exists($post_type, $notif_post_types) ) {
                $message = date_i18n("Y/m/d H:i:s") . " WordPressが" . $update_message . "されました。" . chr(10) . get_permalink($post_ID);
                $inxUpdateNotification->send_message(
                    'google',
                    $results[$target_soeji]->notif_webhook,
                    $results[$target_soeji]->notif_channel,
                    '',
                    $results[$target_soeji]->notif_to,
                    $message
                );
            }
        }
    }

    $target_soeji = 2;
    if ( ! empty( $results[$target_soeji]->notif_webhook ) ) {
        $tmp = esc_html($results[$target_soeji]->notif_post_type);
        $notif_post_types = [];
        if ( ! empty($tmp) ) {
            $tmp_arr = explode($inxUpdateNotification->post_type_split_str, $tmp);
            foreach ( $tmp_arr as $num => $val ) {
                if ( ! empty( $val ) ) {
                    $notif_post_types[$val] = $val;
                }
            }

            $post_type = get_post_type( $post_ID );

            if ( array_key_exists($post_type, $notif_post_types) ) {
                $message = date_i18n("Y/m/d H:i:s") . " WordPressが" . $update_message . "されました。" . chr(10) . get_permalink($post_ID);
                $inxUpdateNotification->send_message(
                    'guilded',
                    $results[$target_soeji]->notif_webhook,
                    '',
                    '',
                    '',
                    $message,
                    [
                        "title" => "WordPressが" . $update_message . "されました。" . chr(10) . get_permalink($post_ID),
                        "url" => get_permalink($post_ID),
                        "message" => date_i18n("Y/m/d H:i:s"),
                    ]
                );
            }
        }
    }

    $target_soeji = 3;
    if ( ! empty( $results[$target_soeji]->notif_webhook ) && ! empty( $results[$target_soeji]->notif_channel ) ) {
        $tmp = esc_html($results[$target_soeji]->notif_post_type);
        $notif_post_types = [];
        if ( ! empty($tmp) ) {
            $tmp_arr = explode($inxUpdateNotification->post_type_split_str, $tmp);
            foreach ( $tmp_arr as $num => $val ) {
                if ( ! empty( $val ) ) {
                    $notif_post_types[$val] = $val;
                }
            }

            $post_type = get_post_type( $post_ID );

            if ( array_key_exists($post_type, $notif_post_types) ) {
                $message = date_i18n("Y/m/d H:i:s") . " WordPressが" . $update_message . "されました。" . chr(10) . get_permalink($post_ID);
                $inxUpdateNotification->send_message(
                    'telegram',
                    $results[$target_soeji]->notif_webhook,
                    $results[$target_soeji]->notif_channel,
                    '',
                    '',
                    $message
                );
            }
        }
    }

    $target_soeji = 4;
    if ( ! empty( $results[$target_soeji]->notif_webhook ) ) {
        $tmp = esc_html($results[$target_soeji]->notif_post_type);
        $notif_post_types = [];
        if ( ! empty($tmp) ) {
            $tmp_arr = explode($inxUpdateNotification->post_type_split_str, $tmp);
            foreach ( $tmp_arr as $num => $val ) {
                if ( ! empty( $val ) ) {
                    $notif_post_types[$val] = $val;
                }
            }

            $post_type = get_post_type( $post_ID );

            if ( array_key_exists($post_type, $notif_post_types) ) {
                $message = date_i18n("Y/m/d H:i:s") . " WordPressが" . $update_message . "されました。" . chr(10) . get_permalink($post_ID);
                $inxUpdateNotification->send_message(
                    'line',
                    $results[$target_soeji]->notif_webhook,
                    '',
                    $results[$target_soeji]->notif_owner,
                    '',
                    $message
                );
            }
        }
    }

    $target_soeji = 5;
    if ( ! empty( $results[$target_soeji]->notif_webhook ) && ! empty( $results[$target_soeji]->notif_channel ) ) {
        $tmp = esc_html($results[$target_soeji]->notif_post_type);
        $notif_post_types = [];
        if ( ! empty($tmp) ) {
            $tmp_arr = explode($inxUpdateNotification->post_type_split_str, $tmp);
            foreach ( $tmp_arr as $num => $val ) {
                if ( ! empty( $val ) ) {
                    $notif_post_types[$val] = $val;
                }
            }

            $post_type = get_post_type( $post_ID );

            if ( array_key_exists($post_type, $notif_post_types) ) {
                $message = date_i18n("Y/m/d H:i:s") . " WordPressが" . $update_message . "されました。" . chr(10) . get_permalink($post_ID);
                $inxUpdateNotification->send_message(
                    'chatwork',
                    $results[$target_soeji]->notif_webhook,
                    $results[$target_soeji]->notif_channel,
                    $results[$target_soeji]->notif_owner,
                    '',
                    $message
                );
            }
        }
    }

    $target_soeji = 6;
    if ( ! empty( $results[$target_soeji]->notif_webhook ) ) {
        $tmp = esc_html($results[$target_soeji]->notif_post_type);
        $notif_post_types = [];
        if ( ! empty($tmp) ) {
            $tmp_arr = explode($inxUpdateNotification->post_type_split_str, $tmp);
            foreach ( $tmp_arr as $num => $val ) {
                if ( ! empty( $val ) ) {
                    $notif_post_types[$val] = $val;
                }
            }

            $post_type = get_post_type( $post_ID );

            if ( array_key_exists($post_type, $notif_post_types) ) {
                $message = date_i18n("Y/m/d H:i:s") . " WordPressが" . $update_message . "されました。" . chr(10) . get_permalink($post_ID);
                $inxUpdateNotification->send_message(
                    'discord',
                    $results[$target_soeji]->notif_webhook,
                    '',
                    $results[$target_soeji]->notif_owner,
                    '',
                    $message
                );
            }
        }
    }
}
add_action( 'save_post', 'inx_catch_saving', 10, 3 );

include_once( ABSPATH . 'wp-admin/includes/plugin.php' );

endif;