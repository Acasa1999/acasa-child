<?php
/**
 * Donor Access
 *
 * Handles WP user creation on donation, magic link WP session upgrade,
 * donor-only content gating, and GiveWP update safety warning.
 *
 * Hook dependency: Give()->email_access->token_exists / token_email
 * set at wp@14 (Give_Email_Access::init). Our hooks run at wp@15.
 *
 * WARNING: If GiveWP is updated, verify Give_Email_Access::init() still
 * fires on the `wp` action at priority 14 and still sets token_exists
 * and token_email before priority 15.
 */

if ( ! defined( 'ABSPATH' ) ) exit;
