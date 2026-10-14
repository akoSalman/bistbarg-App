jQuery(function ($) {
	var $title = $('#bbp-title'), $body = $('#bbp-body'), $image = $('#bbp-image');

	function preview() {
		$('#bbp-prev-title').text($title.val() || 'عنوان اعلان');
		$('#bbp-prev-body').text($body.val() || 'متن اعلان اینجا نمایش داده می‌شود.');
		var src = $image.val();
		$('#bbp-prev-image').toggleClass('bbp-hidden', !src).attr('src', src || '');
	}
	$title.add($body).add($image).on('input change', preview);

	$('input[name=audience]').on('change', function () {
		var single = $('input[name=audience]:checked').val() === 'user';
		$('#bbp-user').toggleClass('bbp-hidden', !single).prop('required', single);
	});

	var frame;
	$('#bbp-pick-image').on('click', function (e) {
		e.preventDefault();
		if (!frame) {
			frame = wp.media({ title: 'انتخاب تصویر اعلان', button: { text: 'استفاده از این تصویر' }, library: { type: 'image' }, multiple: false });
			frame.on('select', function () {
				var a = frame.state().get('selection').first().toJSON();
				var url = (a.sizes && a.sizes.medium_large ? a.sizes.medium_large.url : a.url);
				$image.val(url).trigger('change');
			});
		}
		frame.open();
	});
});
