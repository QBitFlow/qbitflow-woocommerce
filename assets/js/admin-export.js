/* Accounting export on the QBitFlow settings screen: the page is one form, so the button builds a download link instead of nesting a form. */
(function () {
	'use strict';
	var box = document.getElementById('qbitflow-wc-export');
	if (!box) {
		return;
	}
	var button = box.querySelector('button.qbitflow-download');
	if (!button) {
		return;
	}
	button.addEventListener('click', function (event) {
		event.preventDefault();
		var url = new URL(box.dataset.url, window.location.href);
		url.searchParams.set('from', box.querySelector('.qbitflow-from').value);
		url.searchParams.set('to', box.querySelector('.qbitflow-to').value);
		url.searchParams.set('format', box.querySelector('.qbitflow-format').value);
		window.location.href = url.toString();
	});
})();
