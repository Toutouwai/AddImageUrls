$(function() {
	$('#ProcessPageEdit').on('click', '.url-upload-toggle', function(e) {
		e.preventDefault();
		$(this).parents('.InputfieldImage').find('.url-upload-container').slideToggle(300);
	});
});
