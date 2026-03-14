<?php
/*
* This is a copy of the GV officil PHP snippet however its been ammended to only target a specific Gravity View for retrigger
* Pot in place as otherwise it will retrigger notifications on all forms globally when the are updated through GV
*/
add_filter( 'gravityview-inline-edit/entry-updated', 'gravityedit_custom_trigger_notifications', 10, 5 );

function gravityedit_custom_trigger_notifications( $update_result, $entry = array(), $form_id = 0, $gf_field = null, $original_entry = array() ) { 
    
    // CHANGE THIS to GravityView ID
    $target_view_id = 21423;

    if ( empty( $_REQUEST['view_id'] ) || intval( $_REQUEST['view_id'] ) !== $target_view_id ) {
        return $update_result;
    }

    $entry = GFAPI::get_entry( $entry['id'] );
    $form = GFAPI::get_form( $form_id );

    GFCommon::send_form_submission_notifications( $form, $entry );

    return $update_result;
}