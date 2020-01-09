$(function() {
	$('#ProcessPageEdit').on('click', '.url-upload-toggle', function(e) {
		e.preventDefault();
		$(this).parents('.InputfieldImage, .InputfieldFile').find('.url-upload-container').slideToggle(300);
	});
});
