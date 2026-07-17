$(document).on('click', '.tweet-option.tweet-bookmark', function(e) {
  e.stopPropagation();
  let icon = $(this).find('i');
  let tweet_id = $(this).data('tweet');

  $.post('core/ajax/bookmark.php', { tweet_id: tweet_id }, function(data) {
    let result = JSON.parse(data);
    if (result.status === 'added') {
      icon.addClass('bookmarked');
    } else if (result.status === 'removed') {
      icon.removeClass('bookmarked');
    }
  });
});
