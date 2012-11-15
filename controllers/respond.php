<?php
/*
Controller name: Respond
Controller description: Comment/trackback submission methods
*/

class MB_API_Respond_Controller {
  
  function submit_comment() {
    global $mb_api;
    nocache_headers();
    if (empty($_REQUEST['post_id'])) {
      $mb_api->error("No post specified. Include 'post_id' var in your request.");
    } else if (empty($_REQUEST['name']) ||
               empty($_REQUEST['email']) ||
               empty($_REQUEST['content'])) {
      $mb_api->error("Please include all required arguments (name, email, content).");
    } else if (!is_email($_REQUEST['email'])) {
      $mb_api->error("Please enter a valid email address.");
    }
    $pending = new MB_API_Comment();
    return $pending->handle_submission();
  }
  
}

?>
