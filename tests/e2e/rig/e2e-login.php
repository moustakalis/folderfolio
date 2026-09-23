<?php
/**
 * Rig only: what Playground's --login does. Every request is the admin.
 * auth_redirect() reads $_COOKIE, so the cookies go there as well as out.
 */
add_action('plugins_loaded', function () {
    if (defined('WP_CLI') && WP_CLI) {
        return;
    }
    if (!empty($_COOKIE[LOGGED_IN_COOKIE]) && wp_validate_auth_cookie($_COOKIE[LOGGED_IN_COOKIE], 'logged_in')) {
        return;
    }
    $user = get_user_by('login', 'admin');
    if (!$user) {
        return;
    }
    $expires = time() + 14 * DAY_IN_SECONDS;
    // One session token for this request's cookies and the ones sent out, or
    // a nonce printed on this page will not verify on the next.
    $token = WP_Session_Tokens::get_instance($user->ID)->create($expires);
    $_COOKIE[AUTH_COOKIE] = wp_generate_auth_cookie($user->ID, $expires, 'auth', $token);
    $_COOKIE[SECURE_AUTH_COOKIE] = wp_generate_auth_cookie($user->ID, $expires, 'secure_auth', $token);
    $_COOKIE[LOGGED_IN_COOKIE] = wp_generate_auth_cookie($user->ID, $expires, 'logged_in', $token);
    wp_set_auth_cookie($user->ID, true, false, $token);
    wp_set_current_user($user->ID);
}, 0);
