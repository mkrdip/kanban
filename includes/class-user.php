<?php



// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;



Kanban_User::init();



class Kanban_User
{
	private static $instance;

	static function init()
	{
		add_action( 'wp', array(__CLASS__, 'login') );
		add_action( 'wp', array(__CLASS__, 'request_access') );
	}



	static function request_access()
	{
		if (  !isset( $_POST[Kanban_Utils::get_nonce()] ) || ! wp_verify_nonce( $_POST[Kanban_Utils::get_nonce()], 'request_access') ) return;

		$admin_email = get_option('admin_email');
		$blogname = get_option('blogname');

		$headers = "From: " . $admin_email . "\r\n";

		$current_user_id = get_current_user_id();
		$current_user = get_user_by('id', $current_user_id);

		wp_mail(
			$admin_email,
			__( sprintf(
				'%s: %s has requested access',
				Kanban::get_instance()->settings->pretty_name,
				Kanban_User::format_user_name ($current_user)
			), Kanban::get_text_domain() ),
			__( sprintf(
				'The following user has requested access. ' . "\n"
				. '%s' . "\n\n"
				. 'To grant them access, please visit this link:' . "\n"
				. '%s' . "\n"
				. 'And select them as an allowed user.',
				Kanban_User::format_user_name ($current_user),
				admin_url('admin.php?page=' . Kanban::get_instance()->settings->basename)
			), Kanban::get_text_domain() ),
			$headers
		);



		Kanban::get_instance()->flash->add(
			'success',
			__( 'Your request has been sent.', Kanban::get_text_domain() )
		);



		wp_redirect($_POST['_wp_http_referer']);
		exit;
	}



	static function login()
	{
		if (  !isset( $_POST[Kanban_Utils::get_nonce()] ) || ! wp_verify_nonce( $_POST[Kanban_Utils::get_nonce()], 'login') ) return;



		$user_by_email = get_user_by('email', $_POST['email'] );

		if ( empty($user_by_email) )
		{
			Kanban::get_instance()->flash->add(
				'danger',
				__( 'Whoops! We can\'t find an account for that email address.', Kanban::get_text_domain() )
			);
			wp_redirect($_POST['_wp_http_referer']);
			exit;
		}



		$creds = array();
		$creds['user_login'] = $user_by_email->user_login;
		$creds['user_password'] = $_POST['password'];
		$creds['remember'] = true;

		$user = wp_signon( $creds, false );



		if ( is_wp_error($user) )
		{
			Kanban::get_instance()->flash->add(
				'danger',
				__( 'Whoops! That password is incorrect for this email address.', Kanban::get_text_domain() )
			);
			wp_redirect($_POST['_wp_http_referer']);
			exit;
		}



		wp_set_current_user( $user->ID );
	    wp_set_auth_cookie( $user->ID );



		wp_redirect(sprintf('%s/%s/board', site_url(), Kanban::$slug));
		exit;


	} // user_login



	static function get_allowed_users ()
	{
		if ( !isset(Kanban_User::get_instance()->allowed_users) )
		{
			// get all settings
			$allowed_users = Kanban_Option::get_option('allowed_users');

			// pull out allowed user id's
			$allowed_user_ids = array();

			if ( is_array($allowed_users) )
			{
				$allowed_user_ids = $allowed_users;
			}

			if ( empty($allowed_user_ids) )
			{
				$allowed_user_ids = array(0);
			}

			// load actual users
			$users = get_users(array(
				'include' => $allowed_user_ids,
				'fields' => array(
					'ID',
					'user_email',

				)
			));

			// add users to object
			Kanban_User::get_instance()->allowed_users = Kanban_Utils::build_array_with_id_keys($users, 'ID');

			// load extra data
			foreach (Kanban_User::get_instance()->allowed_users as $user_id => $user)
			{
				Kanban_User::get_instance()->allowed_users[$user_id]->caps = array('write');

				// get gravatar
				if(self::validate_gravatar($user->user_email))
				{
					Kanban_User::get_instance()->allowed_users[$user_id]->avatar = get_avatar($user->user_email);
				}

				// fancy name formating
				Kanban_User::get_instance()->allowed_users[$user_id]->long_name_email = Kanban_User::format_user_name ($user);
				Kanban_User::get_instance()->allowed_users[$user_id]->short_name = Kanban_User::format_user_name ($user, TRUE);
				Kanban_User::get_instance()->allowed_users[$user_id]->initials = Kanban_User::get_initials ($user);
			}
		}

		return apply_filters(
			sprintf('%s_after_get_allowed_users', Kanban::get_instance()->settings->basename),
			Kanban_User::get_instance()->allowed_users
		);
	}




	static function format_user_name ($user, $short = FALSE)
	{
		if ( $short )
		{
			if ( !empty($user->first_name) )
			{
				return sprintf('%s %s', $user->first_name, substr($user->last_name, 0, 1));
			}
			else
			{
				$parts = explode("@", $user->user_email);
				$username = $parts[0];
				return $username;
			}
		}
		else
		{
			if ( !empty($user->first_name) )
			{
				return sprintf('%s %s (%s)', $user->first_name, $user->last_name, $user->user_email );
			}
			else
			{
				return $user->user_email;
			}
		}
	}



	static function get_initials ($user)
	{
		if ( !empty($user->first_name) )
		{
			$initials = sprintf(
				'%s%s',
				substr($user->first_name, 0, 1),
				substr($user->last_name, 0, 1)
			);
		}
		else
		{
			$initials = substr($user->user_email, 0, 2);
		}

		return strtoupper($initials);
	}



	/**
	 * Utility function to check if a gravatar exists for a given email or id
	 * @link https://gist.github.com/justinph/5197810
	 * @param int|string|object $id_or_email A user ID,  email address, or comment object
	 * @return bool if the gravatar exists or not
	 */

	static function validate_gravatar($id_or_email) {
	  //id or email code borrowed from wp-includes/pluggable.php
		$email = '';
		if ( is_numeric($id_or_email) ) {
			$id = (int) $id_or_email;
			$user = get_userdata($id);
			if ( $user )
				$email = $user->user_email;
		} elseif ( is_object($id_or_email) ) {
			// No avatar for pingbacks or trackbacks
			$allowed_comment_types = apply_filters( 'get_avatar_comment_types', array( 'comment' ) );
			if ( ! empty( $id_or_email->comment_type ) && ! in_array( $id_or_email->comment_type, (array) $allowed_comment_types ) )
				return false;

			if ( !empty($id_or_email->user_id) ) {
				$id = (int) $id_or_email->user_id;
				$user = get_userdata($id);
				if ( $user)
					$email = $user->user_email;
			} elseif ( !empty($id_or_email->comment_author_email) ) {
				$email = $id_or_email->comment_author_email;
			}
		} else {
			$email = $id_or_email;
		}

		$hashkey = md5(strtolower(trim($email)));
		$uri = 'http://www.gravatar.com/avatar/' . $hashkey . '?d=404';

		$data = wp_cache_get($hashkey);
		if (false === $data) {
			$response = wp_remote_head($uri);
			if( is_wp_error($response) ) {
				$data = 'not200';
			} else {
				$data = $response['response']['code'];
			}
		    wp_cache_set($hashkey, $data, $group = '', $expire = 60*5);

		}
		if ($data == '200'){
			return true;
		} else {
			return false;
		}
	}



	public static function get_instance()
	{
		if ( ! self::$instance )
		{
			self::$instance = new self();
		}
		return self::$instance;
	}



	private function __construct() { }
}


