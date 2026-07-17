<?php foreach ($child_replies as $reply) {

    $reply_user = User::getData($reply->user_id);
    $reply_time = Tweet::getTimeAgo($reply->time);

?>
<div class="box-reply feed" style="margin-left:45px;">

    <div class="grid-tweet">
        <div>
            <img src="assets/images/users/<?php echo $reply_user->img; ?>" class="img-user-tweet">
        </div>

        <div>
            <p>
                <strong><?php echo $reply_user->name ?></strong>
                <span class="username-twitter">@<?php echo $reply_user->username ?></span>
                <span class="username-twitter"><?php echo $reply_time ?></span>
            </p>

            <p><?php echo Tweet::getTweetLinks($reply->reply); ?></p>

            <!-- ✅ Reply icon for deeper replies -->
            <div class="grid-reactions">
                <div class="grid-box-reaction-rep">
                    <div class="hover-reaction-rep hover-reaction-comment reply"
                        data-user="<?php echo $_SESSION['user_id']; ?>"
                        data-tweet="<?php echo $reply->id; ?>">


                        <i class="far fa-comment"></i>
                    </div>
                </div>
            </div>

            <?php
                $next_replies = Tweet::replies($reply->id);
                if (!empty($next_replies)) {
                    $child_replies = $next_replies;
                    include 'reply_recursive.php';
                }
            ?>

        </div>
    </div>

</div>
<?php } ?>
