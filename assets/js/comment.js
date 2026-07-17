$(function(){
	
	$(document).on('click','.comment', function(){
		var tweet_id    = $(this).data('tweet');
		var user_id     = $(this).data('user');
		$counter        = $(this).find(".likes-count");
		$count          = $counter.text();
		$button         = $(this);
		
		console.log(tweet_id);
		console.log(user_id);
		$.post('core/ajax/comment.php', {showPopup:tweet_id,user_id:user_id}, function(data){
			$('.popupComment').html(data);
			 
			$('.close-retweet-popup').click(function(){
				$('.retweet-popup').hide();
			})
		});
	});

	$(document).one('click', '.comment-it', function(event){
		$('.retweet-popup').addClass('active');
		var tweet_id   = $(this).data('tweet');
		var user_id    = $(this).data('user');
		
		var comment ;
		$('.retweet-msg').each(function(){
			comment = $(this).val()
		});
		
		$.post('core/ajax/comment.php', {qoute:tweet_id,user_id:user_id,comment:comment}, function(data){
			$('.retweet-popup').hide();
			$('.comments').html(data);
			location.reload();
		});
	});

	$(document).on('click','.reply', function(){
		var tweet_id    = $(this).data('tweet');
		var user_id     = $(this).data('user');
		$counter        = $(this).find(".likes-count");
		$count          = $counter.text();
		$button         = $(this);
		
		console.log(tweet_id);
		console.log(user_id);
		$.post('core/ajax/comment.php', {showReply:tweet_id,user_id:user_id}, function(data){
			$('.popupComment').html(data);
			 
			$('.close-retweet-popup').click(function(){
				$('.retweet-popup').hide();
			})
		});
	});

	$(document).one('click', '.reply-it', function(event){
		$('.retweet-popup').addClass('active');
		var comment_id   = $(this).data('tweet');
		var user_id    = $(this).data('user');
		
		var comment ;
		$('.retweet-msg').each(function(){
			comment = $(this).val()
		});
		
		$.post('core/ajax/comment.php', {reply:comment_id,user_id:user_id,comment:comment}, function(data){
			$('.retweet-popup').hide();
			$('.comments').html(data);
			location.reload();
		});
	});

	// ========== DELETE FUNCTIONALITY - SIMPLIFIED FIXED VERSION ==========
	
	// Toggle comment dropdown menu - SIMPLIFIED
	$(document).on('click', '.comment-options i', function(e) {
		e.stopPropagation();
		e.preventDefault();
		
		// Get the parent comment-options div and find its dropdown
		var $parent = $(this).closest('.comment-options');
		var $dropdown = $parent.find('.dropdown-menu-comment');
		
		// Close all other dropdowns
		$('.dropdown-menu-comment').not($dropdown).hide();
		$('.dropdown-menu-reply').hide();
		
		// Toggle this dropdown
		if ($dropdown.is(':visible')) {
			$dropdown.hide();
			console.log('Dropdown hidden');
		} else {
			$dropdown.show();
			console.log('Dropdown shown');
		}
	});
	
	// Toggle reply dropdown menu - SIMPLIFIED
	$(document).on('click', '.reply-options i', function(e) {
		e.stopPropagation();
		e.preventDefault();
		
		// Get the parent reply-options div and find its dropdown
		var $parent = $(this).closest('.reply-options');
		var $dropdown = $parent.find('.dropdown-menu-reply');
		
		// Close all other dropdowns
		$('.dropdown-menu-reply').not($dropdown).hide();
		$('.dropdown-menu-comment').hide();
		
		// Toggle this dropdown
		if ($dropdown.is(':visible')) {
			$dropdown.hide();
			console.log('Reply dropdown hidden');
		} else {
			$dropdown.show();
			console.log('Reply dropdown shown');
		}
	});
	
	// Close dropdowns when clicking anywhere else
	$(document).on('click', function(e) {
		// Check if click is inside any dropdown or on the three dots
		if (!$(e.target).closest('.comment-options, .reply-options, .dropdown-menu-comment, .dropdown-menu-reply').length) {
			$('.dropdown-menu-comment, .dropdown-menu-reply').hide();
			console.log('Closed all dropdowns from document click');
		}
	});
	
	// Delete comment
	$(document).on('click', '.delete-comment', function(e) {
		e.preventDefault();
		e.stopPropagation();
		
		var commentId = $(this).data('comment-id');
		var $commentDiv = $(this).closest('.box-comment');
		
		console.log('Deleting comment:', commentId);
		
		if(confirm('Are you sure you want to delete this comment and all its replies? This action cannot be undone.')) {
			$.ajax({
				url: 'core/ajax/comment.php',
				type: 'POST',
				data: { delete_comment: commentId },
				dataType: 'json',
				success: function(response) {
					console.log('Response:', response);
					if(response.success) {
						$commentDiv.fadeOut(300, function() {
							$(this).remove();
							$('#replies-' + commentId).remove();
							showToast('Comment deleted successfully', 'success');
						});
					} else {
						showToast(response.message || 'Error deleting comment', 'error');
					}
				},
				error: function(xhr, status, error) {
					console.log('AJAX Error:', error);
					showToast('An error occurred. Please try again.', 'error');
				}
			});
		}
		
		// Close dropdown
		$(this).closest('.dropdown-menu-comment').hide();
	});
	
	// Delete reply
	$(document).on('click', '.delete-reply', function(e) {
		e.preventDefault();
		e.stopPropagation();
		
		var replyId = $(this).data('reply-id');
		var $replyDiv = $(this).closest('.box-reply');
		
		console.log('Deleting reply:', replyId);
		
		if(confirm('Are you sure you want to delete this reply? This action cannot be undone.')) {
			$.ajax({
				url: 'core/ajax/comment.php',
				type: 'POST',
				data: { delete_reply: replyId },
				dataType: 'json',
				success: function(response) {
					console.log('Response:', response);
					if(response.success) {
						$replyDiv.fadeOut(300, function() {
							$(this).remove();
							showToast('Reply deleted successfully', 'success');
						});
					} else {
						showToast(response.message || 'Error deleting reply', 'error');
					}
				},
				error: function(xhr, status, error) {
					console.log('AJAX Error:', error);
					showToast('An error occurred. Please try again.', 'error');
				}
			});
		}
		
		// Close dropdown
		$(this).closest('.dropdown-menu-reply').hide();
	});
	
	// Toast notification function
	function showToast(message, type) {
		$('.custom-toast').remove();
		
		var toast = $('<div class="custom-toast"></div>');
		toast.css({
			position: 'fixed',
			bottom: '20px',
			left: '50%',
			transform: 'translateX(-50%)',
			backgroundColor: type === 'success' ? '#28a745' : '#dc3545',
			color: '#fff',
			padding: '12px 20px',
			borderRadius: '4px',
			zIndex: '9999',
			fontSize: '14px',
			boxShadow: '0 2px 5px rgba(0,0,0,0.2)',
			opacity: '0',
			transition: 'opacity 0.3s ease'
		});
		toast.html('<i class="fas ' + (type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle') + '"></i> ' + message);
		$('body').append(toast);
		
		setTimeout(function() {
			toast.css('opacity', '1');
		}, 100);
		
		setTimeout(function() {
			toast.css('opacity', '0');
			setTimeout(function() {
				toast.remove();
			}, 300);
		}, 3000);
	}
});