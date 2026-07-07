var handleRenderSummernote = function() {
	var totalHeight = ($(window).width() >= 767) ? $(window).height() - $('.summernote').offset().top - 90 : 400;
	$('.summernote').summernote({
		height: totalHeight
	});
};

var handleEmailTagsInput = function() {
	$('#email-to').tagit({
		availableTags: ["admin2@example.com", "admin3@example.com", "admin4@example.com", "admin5@example.com", "admin6@example.com", "admin7@example.com", "admin8@example.com"]
	});
	$('#email-cc').tagit({
		availableTags: ["admin2@example.com", "admin3@example.com", "admin4@example.com", "admin5@example.com", "admin6@example.com", "admin7@example.com", "admin8@example.com"]
	});
	$('#email-bcc').tagit({
		availableTags: ["admin2@example.com", "admin3@example.com", "admin4@example.com", "admin5@example.com", "admin6@example.com", "admin7@example.com", "admin8@example.com"]
	});
};


/* Controller
------------------------------------------------ */
$(document).ready(function() {
	handleRenderSummernote();
	handleEmailTagsInput();
});