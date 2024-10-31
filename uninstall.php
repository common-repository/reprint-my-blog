<?php
if( !defined( 'ABSPATH') && !defined('WP_UNINSTALL_PLUGIN') )
    exit();

delete_option('rmb_admin_options');
delete_option('rmb_latest_time_end');
delete_option('rmb_latest_time_start');

?>
